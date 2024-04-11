<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022, Varun Patil <radialapps@gmail.com>
 * @author Varun Patil <radialapps@gmail.com>
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\Command;

use OCA\Memories\Db\TimelineWrite;
use OCA\Memories\Service;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IndexOpts
{
    public bool $force = false;
    public bool $clear = false;
    public ?string $user = null;
    public ?string $folder = null;
    public ?string $group = null;
    public bool $retry = false;
    public bool $skipCleanup = false;

    public function __construct(InputInterface $input)
    {
        $this->force = (bool) $input->getOption('force');
        $this->clear = (bool) $input->getOption('clear');
        $this->user = $input->getOption('user');
        $this->folder = $input->getOption('folder');
        $this->retry = (bool) $input->getOption('retry');
        $this->skipCleanup = (bool) $input->getOption('skip-cleanup');
        $this->group = $input->getOption('group');
    }
}

class Index extends Command
{
    private InputInterface $input;
    private OutputInterface $output;
    private IndexOpts $opts;

    public function __construct(
        protected IRootFolder $rootFolder,
        protected IUserManager $userManager,
        protected IGroupManager $groupManager,
        protected IConfig $config,
        protected Service\Index $indexer,
        protected TimelineWrite $tw,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('memories:index')
            ->setDescription('Index the metadata in files')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Index only the specified user')
            ->addOption('group', 'g', InputOption::VALUE_REQUIRED, 'Index only specified group')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Index only the specified folder (relative to the user\'s root)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force refresh of existing index entries')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Clear all existing index entries')
            ->addOption('retry', null, InputOption::VALUE_NONE, 'Retry indexing of failed files')
            ->addOption('skip-cleanup', null, InputOption::VALUE_NONE, 'Skip cleanup step (removing index entries with missing files)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var \Symfony\Component\Console\Output\ConsoleOutputInterface $output */
        $output = $output;

        // Store input/output/opts for later use
        $this->input = $input;
        $this->output = $output;
        $this->opts = new IndexOpts($input);

        // Assign to indexer
        $this->indexer->output = $output;
        $this->indexer->section = $output->section();
        $this->indexer->verbose = $output->isVerbose();

        try {
            // Use static exiftool process
            \OCA\Memories\Exif::ensureStaticExiftoolProc();
            Service\BinExt::testExiftool(); // throws

            // Perform steps based on opts
            $this->checkClear();
            $this->checkForce();
            $this->checkRetry();

            // Run the indexer
            $this->runIndex();

            // Clean up the index
            if (!$this->opts->skipCleanup) {
                $this->indexer->cleanupStale();
            }

            // Warn about skipped files
            $this->warnRetry();

            return 0;
        } catch (\Exception $e) {
            $this->output->writeln("<error>{$e->getMessage()}</error>".PHP_EOL);

            return 1;
        } finally {
            \OCA\Memories\Exif::closeStaticExiftoolProc();
        }
    }

    /**
     * Check and act on the clear option if set.
     */
    protected function checkClear(): void
    {
        if (!$this->opts->clear) {
            return;
        }

        if ($this->input->isInteractive()) {
            $this->output->writeln('<error>Clearing the index can have unintended consequences like');
            $this->output->writeln('duplicates for some files appearing on the mobile app.');
            $this->output->writeln('Using --force instead of --clear is recommended in most cases.</error>');
            $this->output->write('Are you sure you want to clear the existing index? (y/N): ');
            if ('y' !== trim(fgets(STDIN))) {
                throw new \Exception('Aborting');
            }
        }

        $this->tw->clear();
        $this->output->writeln('<info>Cleared existing index</info>');
    }

    /**
     * Check and act on the force option if set.
     */
    protected function checkForce(): void
    {
        if (!$this->opts->force) {
            return;
        }

        $this->output->writeln('Forcing refresh of existing index entries');
        $this->tw->orphanAll();
    }

    /**
     * Check and act on the retry option if set.
     */
    protected function checkRetry(): void
    {
        if (!$this->opts->retry) {
            return;
        }

        $this->output->writeln('<info>Retrying indexing of failed files</info>');
        $this->tw->clearAllFailures();
    }

    /**
     * Warn about skipped files (called at the end of indexing).
     */
    protected function warnRetry(): void
    {
        if ($count = $this->tw->countFailures()) {
            $this->output->writeln("Indexing skipped for {$count} failed files, use --retry to try again");
        }
    }

    /**
     * Run the indexer.
     */
    protected function runIndex(): void
    {
        $this->runForUsers(function (IUser $user) {
            try {
                $this->indexer->indexUser($user, $this->opts->folder);
            } catch (\Exception $e) {
                $this->output->writeln("<error>{$e->getMessage()}</error>".PHP_EOL);
            }
        });
    }

    /**
     * Run function for all users (or selected user if set).
     *
     * @param \Closure(IUser $user): void $closure
     */
    private function runForUsers(\Closure $closure): void
    {
        if ($uid = $this->opts->user) {
            if ($user = $this->userManager->get($uid)) {
                $closure($user);
            } else {
                $this->output->writeln("<error>User {$uid} not found</error>".PHP_EOL);
            }
        } elseif ($gid = $this->opts->group) {
            if ($group = $this->groupManager->get($gid)) {
                foreach ($group->getUsers() as $user) {
                    $closure($user);
                }
            } else {
                $this->output->writeln("<error>Group {$gid} not found</error>".PHP_EOL);
            }
        } else {
            $this->userManager->callForSeenUsers($closure);
        }
    }
}
