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

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Db\SQL;
use OCA\Memories\Db\TimelineWrite;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IPreview;
use OCP\ITempManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

class Index
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

    /** @var string[] */
    private static ?array $mimeList = null;

    public function __construct(
        protected IRootFolder $rootFolder,
        protected TimelineWrite $tw,
        protected IDBConnection $db,
        protected ITempManager $tempManager,
        protected LoggerInterface $logger,
        protected IAppManager $appManager,
    ) {}

    /**
     * Index all files for a user.
     */
    public function indexUser(IUser $user, ?string $folder = null): void
    {
        if (!$this->appManager->isEnabledForUser('memories', $user)) {
            return;
        }

        $uid = $user->getUID();

        $this->log("<info>Indexing user {$uid}</info>".PHP_EOL, true);

        \OC_Util::tearDownFS();
        \OC_Util::setupFS($uid);

        // Get the root folder of the user
        $root = $this->rootFolder->getUserFolder($uid);

        // Get paths of folders to index
        $mode = SystemConfig::get('memories.index.mode');
        if (null !== $folder) {
            $paths = [$folder];
        } elseif ('1' === $mode || '0' === $mode) { // everything (or nothing)
            $paths = ['/'];
        } elseif ('2' === $mode) { // timeline
            $paths = Util::getTimelinePaths($uid);
        } elseif ('3' === $mode) { // custom
            $paths = [SystemConfig::get('memories.index.path')];
        } else {
            throw new \Exception('Invalid index mode');
        }

        // If a folder is specified, traverse only that folder
        foreach ($paths as $path) {
            try {
                $node = $root->get($path);
                if (!$node instanceof Folder) {
                    throw new \Exception('Not a folder');
                }
            } catch (\Exception $e) {
                // Only log this if we're on the CLI, do not put an error in the logs
                // https://github.com/pulsejet/memories/issues/1091
                $this->log("<error>The specified folder {$path} does not exist for {$uid}</error>".PHP_EOL);

                continue;
            }

            $this->indexFolder($node);
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

        // Check if path is blacklisted
        if (!$this->isPathAllowed($path.'/')) {
            $this->log("Skipping folder {$path} (path excluded)".PHP_EOL, true);

            return;
        }

        // Check if folder contains exclusion file
        if ($folder->nodeExists('.nomedia') || $folder->nodeExists('.nomemories')) {
            $this->log("Skipping folder {$path} (.nomedia / .nomemories)".PHP_EOL, true);

            return;
        }

        // Get all files and folders in this folders
        $nodes = $folder->getDirectoryListing();

        // Filter files that are supported
        $mimes = self::getMimeList();
        $files = array_filter($nodes, static fn ($n): bool => $n instanceof File
            && \in_array($n->getMimeType(), $mimes, true)
            && self::isPathAllowed($n->getPath()));

        // Create an associative array with file ID as key
        $files = array_combine(array_map(static fn ($n) => $n->getId(), $files), $files);

        // Chunk array into some files each (DBs have limitations on IN clause)
        $chunks = array_chunk($files, 250, true);

        // Check files in each chunk
        foreach ($chunks as $chunk) {
            $fileIds = array_keys($chunk);

            // Select all files in filecache
            $query = $this->db->getQueryBuilder();
            $query->select('f.fileid')
                ->from('filecache', 'f')
                ->where($query->expr()->in('f.fileid', $query->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($query->expr()->gt('f.size', $query->expr()->literal(0)))
            ;

            // Filter out files that are already indexed
            $getFilter = function (string $table, bool $notOrpaned) use (&$query): IQueryFunction {
                // Make subquery to check if file exists in table
                $clause = $this->db->getQueryBuilder();
                $clause->select($clause->expr()->literal(1))
                    ->from($table, 'a')
                    ->andWhere($clause->expr()->eq('f.fileid', 'a.fileid'))
                    ->andWhere($clause->expr()->eq('f.mtime', 'a.mtime'))
                ;

                // Filter only non-orphaned files
                if ($notOrpaned) {
                    $clause->andWhere($clause->expr()->eq('a.orphan', $clause->expr()->literal(0)));
                }

                // Add the clause to the main query
                return SQL::notExists($query, $clause);
            };

            // Filter out files that are already indexed or failed
            $query->andWhere($getFilter('memories', true));
            $query->andWhere($getFilter('memories_livephoto', true));
            $query->andWhere($getFilter('memories_failures', false));

            // Get file IDs to actually index
            $fileIds = Util::transaction(static fn (): array => $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));

            // Index files
            foreach ($fileIds as $fileId) {
                $this->ensureContinueOk();
                $this->indexFile($chunk[$fileId]);
            }
        }

        // All folders
        $folders = array_filter($nodes, static fn ($n) => $n instanceof Folder);
        foreach ($folders as $folder) {
            $this->ensureContinueOk();

            try {
                $this->indexFolder($folder);
            } catch (ProcessClosedException $e) {
                throw $e;
            } catch (\Exception $e) {
                $this->error("Failed to index folder {$folder->getPath()}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Index a single file.
     */
    public function indexFile(File $file): void
    {
        $path = $file->getPath();

        try {
            $this->log("Indexing file {$path}", true);
            $this->tw->processFile($file);
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
     * Get list of MIME types to process.
     */
    public static function getMimeList(): array
    {
        return self::$mimeList ??= array_merge(
            self::getPreviewMimes(Application::IMAGE_MIMES),
            Application::VIDEO_MIMES,
        );
    }

    /**
     * Get list of MIME types that have a preview.
     */
    public static function getPreviewMimes(array $source): array
    {
        $preview = \OC::$server->get(IPreview::class);

        return array_filter($source, static fn ($m) => $preview->isMimeSupported($m));
    }

    /**
     * Get list of all supported MIME types.
     */
    public static function getAllMimes(): array
    {
        return array_merge(
            Application::IMAGE_MIMES,
            Application::VIDEO_MIMES,
        );
    }

    /**
     * Check if a file is supported.
     *
     * @param Node $file file to check
     */
    public static function isSupported(Node $file): bool
    {
        return \in_array($file->getMimeType(), self::getMimeList(), true);
    }

    /**
     * Check if a file is a video.
     *
     * @param Node $file file to check
     */
    public static function isVideo(Node $file): bool
    {
        return \in_array($file->getMimeType(), Application::VIDEO_MIMES, true);
    }

    /**
     * Checks if the specified node's path is allowed to be indexed.
     */
    public static function isPathAllowed(string $path): bool
    {
        // Always exclude some predefined patterns
        //   .trashed-<file> (https://github.com/nextcloud/android/issues/10645)
        if (preg_match('/\/.trashed-[^\/]*$/', $path)) {
            return false;
        }

        /** @var ?string $pattern */
        static $pattern = null;

        if (null === $pattern) {
            $pattern = trim(SystemConfig::get('memories.index.path.blacklist') ?: '');
            if (!empty($pattern) && !\is_int(preg_match("/{$pattern}/", ''))) {
                throw new \Exception('Invalid regex pattern in memories.index.path.blacklist');
            }
        }

        return empty($pattern) || !preg_match("/{$pattern}/", $path);
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
            }
            $this->section->write($message);
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
