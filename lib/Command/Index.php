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
    public ?string $path = null;
    public ?string $group = null;
    public bool $retry = false;
    public bool $skipCleanup = false;
    public int $jobs = 1;

    public function __construct(InputInterface $input)
    {
        $this->force = (bool) $input->getOption('force');
        $this->clear = (bool) $input->getOption('clear');
        $this->user = $input->getOption('user');
        $this->path = $input->getOption('path');
        $this->retry = (bool) $input->getOption('retry');
        $this->skipCleanup = (bool) $input->getOption('skip-cleanup');
        $this->group = $input->getOption('group');
        $this->jobs = max(1, (int) ($input->getOption('jobs') ?? 1));
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
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Index only the specified folder or file (relative to the user\'s root)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force refresh of existing index entries')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Clear all existing index entries')
            ->addOption('retry', null, InputOption::VALUE_NONE, 'Retry indexing of failed files')
            ->addOption('skip-cleanup', null, InputOption::VALUE_NONE, 'Skip cleanup step (removing index entries with missing files)')
            ->addOption('jobs', 'j', InputOption::VALUE_REQUIRED, 'Number of parallel indexing jobs (requires pcntl)', '1')
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

        // Check if parallel processing is requested but not available
        if ($this->opts->jobs > 1 && !\extension_loaded('pcntl')) {
            $this->output->writeln('<error>Parallel processing requires the pcntl extension</error>');
            $this->output->writeln('<info>Falling back to single-threaded mode</info>'.PHP_EOL);
            $this->opts->jobs = 1;
        }

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

            // Run the indexer (parallel or single-threaded)
            if ($this->opts->jobs > 1) {
                $this->runIndexParallel();
            } else {
                $this->runIndex();
            }

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
                $this->indexer->indexUser($user, $this->opts->path);
            } catch (\Exception $e) {
                $this->output->writeln("<error>{$e->getMessage()}</error>".PHP_EOL);
            }
        });
    }

    /**
     * Run the indexer in parallel using multiple worker processes.
     */
    protected function runIndexParallel(): void
    {
        $numJobs = $this->opts->jobs;

        // If user/path specified, use filtered approach; otherwise use fast DB query
        if ($this->opts->user || $this->opts->path || $this->opts->group) {
            $this->runIndexParallelFiltered();

            return;
        }

        $this->output->writeln('<info>Querying database for files needing indexing...</info>');

        // Single database query to find all files needing indexing (fastest)
        $allFileIds = $this->indexer->getFilesNeedingIndex();

        $numFiles = \count($allFileIds);
        if (0 === $numFiles) {
            $this->output->writeln('<info>No files need indexing</info>');

            return;
        }

        // Partition file IDs among workers
        $partitions = $this->partitionArray($allFileIds, $numJobs);
        $actualJobs = \count(array_filter($partitions, static fn ($p) => !empty($p)));

        $this->output->writeln("<info>Found {$numFiles} file(s) to index, using {$actualJobs} parallel job(s)</info>");
        $this->output->writeln('');

        // Reserve lines for worker status display
        for ($i = 0; $i < $actualJobs; ++$i) {
            $this->output->writeln("[Worker {$i}] Starting...");
        }

        // Close exiftool before forking - each child will create its own
        \OCA\Memories\Exif::closeStaticExiftoolProc();

        $pids = [];
        $workerNum = 0;

        foreach ($partitions as $workerIndex => $fileIdPartition) {
            if (empty($fileIdPartition)) {
                continue;
            }

            $pid = pcntl_fork();

            if (-1 === $pid) {
                $this->output->writeln('<error>Failed to fork worker process</error>');

                continue;
            }

            if (0 === $pid) {
                // Child process - directly index assigned file IDs
                // Pass the line offset for display (count from bottom of reserved area)
                $lineOffset = $actualJobs - $workerNum;
                $this->runWorker($workerNum, $fileIdPartition, $lineOffset);
                exit(0);
            }

            // Parent process
            $pids[] = $pid;
            ++$workerNum;
        }

        // Wait for all children to complete
        $exitCodes = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        // Move cursor below the status area
        $this->output->writeln('');

        // Re-initialize exiftool for cleanup phase
        \OCA\Memories\Exif::ensureStaticExiftoolProc();

        // Report results
        $failed = \count(array_filter($exitCodes, static fn ($code) => 0 !== $code));
        if ($failed > 0) {
            $this->output->writeln("<comment>{$failed} worker(s) exited with errors</comment>");
        }

        $this->output->writeln('<info>All workers finished</info>'.PHP_EOL);
    }

    /**
     * Run parallel indexing with user/path filters (uses folder traversal).
     */
    protected function runIndexParallelFiltered(): void
    {
        $users = $this->collectUsers();

        if (empty($users)) {
            $this->output->writeln('<info>No users to index</info>');

            return;
        }

        $numJobs = $this->opts->jobs;
        $this->output->writeln('<info>Scanning for files to index (filtered mode)...</info>');

        // Collect files by traversing folders (respects user/path filters)
        $allFileIds = [];
        foreach ($users as $user) {
            try {
                $userFiles = $this->indexer->getFilesForUser($user, $this->opts->path);
                foreach ($userFiles as $fileId) {
                    $allFileIds[$fileId] = true;
                }
            } catch (\Exception $e) {
                $this->output->writeln("<error>Error scanning user {$user->getUID()}: {$e->getMessage()}</error>");
            }
        }

        $numFiles = \count($allFileIds);
        if (0 === $numFiles) {
            $this->output->writeln('<info>No files need indexing</info>');

            return;
        }

        $fileIdList = array_keys($allFileIds);
        $partitions = $this->partitionArray($fileIdList, $numJobs);
        $actualJobs = \count(array_filter($partitions, static fn ($p) => !empty($p)));

        $this->output->writeln("<info>Found {$numFiles} file(s) to index, using {$actualJobs} parallel job(s)</info>");
        $this->output->writeln('');

        // Reserve lines for worker status display
        for ($i = 0; $i < $actualJobs; ++$i) {
            $this->output->writeln("[Worker {$i}] Starting...");
        }

        \OCA\Memories\Exif::closeStaticExiftoolProc();

        $pids = [];
        $workerNum = 0;

        foreach ($partitions as $workerIndex => $fileIdPartition) {
            if (empty($fileIdPartition)) {
                continue;
            }

            $pid = pcntl_fork();
            if (-1 === $pid) {
                $this->output->writeln('<error>Failed to fork worker process</error>');

                continue;
            }

            if (0 === $pid) {
                $lineOffset = $actualJobs - $workerNum;
                $this->runWorker($workerNum, $fileIdPartition, $lineOffset);
                exit(0);
            }

            $pids[] = $pid;
            ++$workerNum;
        }

        $exitCodes = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        $this->output->writeln('');
        \OCA\Memories\Exif::ensureStaticExiftoolProc();

        $failed = \count(array_filter($exitCodes, static fn ($code) => 0 !== $code));
        if ($failed > 0) {
            $this->output->writeln("<comment>{$failed} worker(s) exited with errors</comment>");
        }

        $this->output->writeln('<info>All workers finished</info>'.PHP_EOL);
    }

    /**
     * Partition an array into n roughly equal chunks.
     *
     * @param array<int> $array    Array to partition
     * @param int        $numParts Number of partitions
     *
     * @return array<int, array<int>> Partitioned arrays
     */
    private function partitionArray(array $array, int $numParts): array
    {
        $count = \count($array);
        if (0 === $count) {
            return array_fill(0, $numParts, []);
        }

        $partitions = [];
        $chunkSize = (int) ceil($count / $numParts);

        for ($i = 0; $i < $numParts; ++$i) {
            $partitions[$i] = \array_slice($array, $i * $chunkSize, $chunkSize);
        }

        return $partitions;
    }

    /**
     * Run a worker process that indexes assigned files by ID.
     *
     * @param int        $workerIndex Worker identifier
     * @param array<int> $fileIds     File IDs to process
     * @param int        $lineOffset  Line offset from cursor for status updates
     */
    private function runWorker(int $workerIndex, array $fileIds, int $lineOffset): void
    {
        // Each worker needs its own exiftool process
        \OCA\Memories\Exif::ensureStaticExiftoolProc();

        try {
            // Process files directly by ID - real-time line updates
            $this->indexer->indexByIds($fileIds, $workerIndex, $lineOffset);
        } catch (\Exception $e) {
            $this->updateWorkerLine($lineOffset, "[Worker {$workerIndex}] Error: {$e->getMessage()}");
        } finally {
            \OCA\Memories\Exif::closeStaticExiftoolProc();
        }
    }

    /**
     * Update a specific line in the terminal using ANSI escape codes.
     *
     * @param int    $lineOffset Lines up from current cursor position
     * @param string $content    Content to display
     */
    private function updateWorkerLine(int $lineOffset, string $content): void
    {
        // Move up, clear line, write content, move back down
        fwrite(STDERR, "\033[{$lineOffset}A\r\033[K{$content}\033[{$lineOffset}B\r");
    }

    /**
     * Collect all users that need to be indexed.
     *
     * @return IUser[]
     */
    private function collectUsers(): array
    {
        $users = [];

        if ($uid = $this->opts->user) {
            if ($user = $this->userManager->get($uid)) {
                $users[] = $user;
            } else {
                $this->output->writeln("<error>User {$uid} not found</error>".PHP_EOL);
            }
        } elseif ($gid = $this->opts->group) {
            if ($group = $this->groupManager->get($gid)) {
                $users = array_values($group->getUsers());
            } else {
                $this->output->writeln("<error>Group {$gid} not found</error>".PHP_EOL);
            }
        } else {
            $this->userManager->callForSeenUsers(static function (IUser $user) use (&$users): void {
                $users[] = $user;
            });
        }

        return $users;
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
