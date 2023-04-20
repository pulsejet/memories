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

    public function __construct(InputInterface $input)
    {
        $this->force = (bool) $input->getOption('force');
        $this->clear = (bool) $input->getOption('clear');
        $this->user = $input->getOption('user');
        $this->folder = $input->getOption('folder');
    }
}

class Index extends Command
{
    /** @var int[][] */
    protected array $sizes;

    protected IUserManager $userManager;
    protected IRootFolder $rootFolder;
    protected IConfig $config;
    protected Service\Index $indexer;
    protected TimelineWrite $timelineWrite;

    // IO
    private InputInterface $input;
    private OutputInterface $output;

    // Command options
    private IndexOpts $opts;

    public function __construct(
        IRootFolder $rootFolder,
        IUserManager $userManager,
        IConfig $config,
        Service\Index $indexer,
        TimelineWrite $timelineWrite
    ) {
        parent::__construct();

        $this->userManager = $userManager;
        $this->rootFolder = $rootFolder;
        $this->config = $config;
        $this->indexer = $indexer;
        $this->timelineWrite = $timelineWrite;
    }

    protected function configure(): void
    {
        $this
            ->setName('memories:index')
            ->setDescription('Generate photo entries')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Index only the specified user')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Index only the specified folder (relative to the user\'s root)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force refresh of existing index entries')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Clear all existing index entries')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Store input/output/opts for later use
        $this->input = $input;
        $this->output = $output;
        $this->opts = new IndexOpts($input);

        // Assign to indexer
        $this->indexer->output = $output;
        $this->indexer->section = $output->section();

        try {
            // Use static exiftool process
            \OCA\Memories\Exif::ensureStaticExiftoolProc();
            if (!Service\BinExt::testExiftool()) { // throws
                throw new \Exception('exiftool could not be executed or test failed');
            }

            // Perform steps based on opts
            $this->checkClear();
            $this->checkForce();

            // Run the indexer
            $this->runIndex();

            return 0;
        } catch (\Exception $e) {
            $this->output->writeln("<error>{$e->getMessage()}</error>");

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
            $this->output->write('Are you sure you want to clear the existing index? (y/N): ');
            if ('y' !== trim(fgets(STDIN))) {
                throw new \Exception('Aborting');
            }
        }

        $this->timelineWrite->clear();
        $this->output->writeln('Cleared existing index');
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

        $this->timelineWrite->orphanAll();
    }

    /**
     * Run the indexer.
     */
    protected function runIndex(): void
    {
        $this->runForUsers(function (IUser $user) {
            try {
                $uid = $user->getUID();
                $this->output->writeln("Indexing user {$uid}");
                $this->indexer->indexUser($uid, $this->opts->folder);
            } catch (\Exception $e) {
                $this->output->writeln("<error>{$e->getMessage()}</error>");
            }
        });
    }

    /**
     * Run function for all users (or selected user if set).
     *
     * @param mixed $closure
     */
    private function runForUsers($closure)
    {
        if ($uid = $this->opts->user) {
            if ($user = $this->userManager->get($uid)) {
                $closure($user);
            } else {
                $this->output->writeln("<error>User {$uid} not found</error>");
            }
        } else {
            $this->userManager->callForSeenUsers(fn (IUser $user) => $closure($user));
        }
    }
}
