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

namespace OCA\Memories\Service;

use OC\Files\SetupManager;
use OCA\Memories\Db\FsManager;
use OCA\Memories\Db\IndexQuery;
use OCA\Memories\Db\SQL;
use OCA\Memories\Db\TimelineRoot;
use OCA\Memories\Db\TimelineWrite;
use OCA\Memories\Settings\SystemConfig;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\ITempManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class Index
{
    public ?OutputInterface $output = null;
    public ?ConsoleSectionOutput $section = null;
    public bool $verbose = false;

    /**
     * Callback to check if the process should continue.
     * This is called before every file is indexed.
     *
     * @var null|\Closure(): bool
     */
    public ?\Closure $continueCheck = null;

    public function __construct(
        private IRootFolder $rootFolder,
        private TimelineWrite $tw,
        private IndexQuery $indexQuery,
        private FsManager $fsManager,
        private IDBConnection $db,
        private SystemConfig $systemConfig,
        private ITempManager $tempManager,
        private LoggerInterface $logger,
        private IAppManager $appManager,
        private SetupManager $setupManager,
        private Lens $lens,
        private MIME $mime,
    ) {}

    /**
     * Index all files for a user.
     */
    public function indexUser(IUser $user, ?string $forcePath = null): void
    {
        if (!$this->appManager->isEnabledForUser('memories', $user)) {
            return;
        }

        $uid = $user->getUID();

        $this->log("<info>Indexing user {$uid}</info>".PHP_EOL, true);

        $this->setupManager->tearDown();
        $this->setupManager->setupForUser($user);

        // Get the root folder of the user
        $userFolder = $this->rootFolder->getUserFolder($uid);

        // Get paths of folders to index
        $mode = $this->systemConfig->get('memories.index.mode');
        if (null !== $forcePath) {
            $paths = [$forcePath];
        } elseif ('1' === $mode || '0' === $mode) { // everything (or nothing)
            $paths = ['/'];
        } elseif ('2' === $mode) { // timeline
            $paths = $this->systemConfig->getTimelinePaths($uid);
        } elseif ('3' === $mode) { // custom
            $paths = [$this->systemConfig->get('memories.index.path')];
        } else {
            throw new \Exception('Invalid index mode');
        }

        // If a folder is specified, traverse only that folder
        $indexPaths = [];
        foreach ($paths as $path) {
            try {
                $node = $userFolder->get($path);
            } catch (\Exception $e) {
                // Only log this if we're on the CLI, do not put an error in the logs
                // https://github.com/pulsejet/memories/issues/1091
                $this->log("<error>The specified folder {$path} does not exist for {$uid}</error>".PHP_EOL);

                continue;
            }

            if ($node instanceof Folder) {
                $indexPaths[] = $path;
            } elseif ($node instanceof File) {
                $this->indexFile($node);
            } else {
                throw new \Exception('Not a file or folder');
            }
        }

        // Index all paths including mounts.
        if (\count($indexPaths) > 0) {
            $root = new TimelineRoot();
            $this->fsManager->populateRoot($root, true, $user, $indexPaths);
            $this->indexFolderIds($userFolder, $root->getIds());
        }
    }

    /**
     * Index all files under the given top folder ids.
     *
     * @param Folder $folder Folder to materialize candidates in (scopes getById)
     * @param int[]  $topIds top folder fileids to crawl, mounts already expanded
     */
    public function indexFolderIds(Folder $folder, array $topIds): void
    {
        foreach ($this->indexQuery->getCandidateBatches($topIds) as $batch) {
            foreach ($batch as $fileId) {
                $this->ensureContinueOk();

                try {
                    $node = $folder->getById($fileId)[0] ?? null;
                    if (!$node instanceof File) {
                        throw new \Exception('Not a file');
                    }
                    $this->indexFile($node, failSkip: true);
                } catch (\Exception $e) {
                    $this->error("Failed to index file {$fileId}: {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * Index all files in a folder.
     *
     * @param Folder $folder folder to index
     */
    public function indexFolder(Folder $folder): void
    {
        $path = $folder->getPath();
        $this->log("Indexing folder {$path}", true);
        $this->indexFolderIds($folder, [$folder->getId()]);
    }

    /**
     * Index a single file.
     */
    public function indexFile(File $file, bool $failSkip = false): void
    {
        $path = $file->getPath();

        try {
            // Check if this file should be indexed.
            // https://github.com/pulsejet/memories/issues/933 (zero-byte files)
            if ($file->getSize() <= 0
                || !$this->mime->isSupported($file)
                || !$this->mime->isPathAllowed($path)) {
                // Drift between SQL and PHP enforcement would wedge the batch
                // on this file, so mark it failed to keep making progress
                if ($failSkip) {
                    throw new \Exception('File does not meet indexing criteria');
                }

                return;
            }

            // Checks passed - index the file.
            $this->log("Indexing file {$path}", true);
            $this->tw->processFile(
                file: $file,
                validate: function () use ($file): bool {
                    return !$this->indexQuery->isIndexed($file->getId(), $file->getMtime());
                },
            );

            // Queue indexing in the Lens daemon if enabled.
            $this->lens->enqueue($file);
        } catch (\OCP\Lock\LockedException $e) {
            $this->log("Skipping file {$path} due to lock", true);
        } catch (\Exception $e) {
            $this->error("Failed to index file {$path}: {$e->getMessage()}");
            $this->tw->markFailed($file, $e->getMessage());
        } finally {
            $this->tempManager->clean();
        }
    }

    /**
     * Cleanup all stale entries (passthrough to timeline write).
     */
    public function cleanupStale(): void
    {
        $this->log('<info>Cleaning up stale index entries</info>');
        $this->tw->cleanupStale();
    }

    /**
     * Get total number of files that are indexed.
     */
    public function getIndexedCount(): int
    {
        $query = $this->db->getQueryBuilder();
        $query->select($query->func()->count(SQL::distinct($query, 'fileid')))
            ->from('memories')
        ;

        return (int) $query->executeQuery()->fetchOne();
    }

    /**
     * Log error to console if CLI or logger.
     */
    private function error(string $message): void
    {
        $this->logger->error($message, ['app' => 'memories']);
        $this->output?->writeln("<error>{$message}</error>".PHP_EOL);
    }

    /**
     * Log to console if CLI.
     */
    private function log(string $message, bool $overwrite = false): void
    {
        if ($this->section) {
            if ($overwrite && !$this->verbose) {
                $this->section->clear(1);
                $this->section->write($message);
            } else {
                $this->section->writeln($message);
            }
        }
    }

    /**
     * Ensure that the process should go on.
     */
    private function ensureContinueOk(): void
    {
        if (null !== $this->continueCheck && !($this->continueCheck)()) {
            throw new ProcessClosedException();
        }
    }
}
