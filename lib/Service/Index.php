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
use OCA\Memories\Db\TimelineWrite;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IPreview;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

class Index
{
    public ?OutputInterface $output = null;
    public ?ConsoleSectionOutput $section = null;

    /**
     * Callback to check if the process should continue.
     * This is called before every file is indexed.
     */
    public ?\Closure $continueCheck = null;

    protected IRootFolder $rootFolder;
    protected TimelineWrite $timelineWrite;
    protected IDBConnection $db;
    protected ITempManager $tempManager;
    protected LoggerInterface $logger;

    private static ?array $mimeList = null;

    public function __construct(
        IRootFolder $rootFolder,
        TimelineWrite $timelineWrite,
        IDBConnection $db,
        ITempManager $tempManager,
        LoggerInterface $logger
    ) {
        $this->rootFolder = $rootFolder;
        $this->timelineWrite = $timelineWrite;
        $this->db = $db;
        $this->tempManager = $tempManager;
        $this->logger = $logger;
    }

    /**
     * Index all files for a user.
     */
    public function indexUser(string $uid, ?string $folder = null): void
    {
        $this->log("<info>Indexing user {$uid}</info>".PHP_EOL);

        \OC_Util::tearDownFS();
        \OC_Util::setupFS($uid);

        // Get the root folder of the user
        $root = $this->rootFolder->getUserFolder($uid);

        // Get paths of folders to index
        $mode = Util::getSystemConfig('memories.index.mode');
        if (null !== $folder) {
            $paths = [$folder];
        } elseif ('1' === $mode || '0' === $mode) { // everything (or nothing)
            $paths = ['/'];
        } elseif ('2' === $mode) { // timeline
            $paths = Util::getTimelinePaths($uid);
        } elseif ('3' === $mode) { // custom
            $paths = [Util::getSystemConfig('memories.index.path')];
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
                $this->error("The specified folder {$path} does not exist for {$uid}");

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
        if ($folder->nodeExists('.nomedia') || $folder->nodeExists('.nomemories')) {
            $this->log("Skipping folder {$folder->getPath()} (.nomedia / .nomemories)\n", true);

            return;
        }

        // Get all files and folders in this folders
        $nodes = $folder->getDirectoryListing();

        // Filter files that are supported
        $mimes = self::getMimeList();
        $files = array_filter($nodes, static fn ($n) => $n instanceof File && \in_array($n->getMimeType(), $mimes, true));

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
            ;

            // Filter out files that are already indexed
            $addFilter = static function (string $table, string $alias) use (&$query): void {
                $query->leftJoin('f', $table, $alias, $query->expr()->andX(
                    $query->expr()->eq('f.fileid', "{$alias}.fileid"),
                    $query->expr()->eq('f.mtime', "{$alias}.mtime"),
                    $query->expr()->eq("{$alias}.orphan", $query->expr()->literal(0))
                ));

                $query->andWhere($query->expr()->isNull("{$alias}.fileid"));
            };
            $addFilter('memories', 'm');
            $addFilter('memories_livephoto', 'lp');

            // Get file IDs to actually index
            $fileIds = $query->executeQuery()->fetchAll(\PDO::FETCH_COLUMN);

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
                $this->logger->error('Failed to index folder {folder}: {error}', [
                    'folder' => $folder->getPath(),
                    'error' => $e->getMessage(),
                ]);
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
            $this->timelineWrite->processFile($file);
        } catch (\OCP\Lock\LockedException $e) {
            $this->log("Skipping file {$path} due to lock", true);
        } catch (\Exception $e) {
            $this->error("Failed to index file {$path}: {$e->getMessage()}");
        } finally {
            $this->tempManager->clean();
        }
    }

    /**
     * Cleanup all stale entries (passthrough to timeline write).
     */
    public function cleanupStale(): void
    {
        $this->log('<info>Cleaning up stale index entries</info>'.PHP_EOL);
        $this->timelineWrite->cleanupStale();
    }

    /**
     * Get total number of files that are indexed.
     */
    public function getIndexedCount(): int
    {
        $query = $this->db->getQueryBuilder();
        $query->select($query->createFunction('COUNT(DISTINCT fileid)'))
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
     */
    public static function isSupported(Node $file): bool
    {
        return \in_array($file->getMimeType(), self::getMimeList(), true);
    }

    /**
     * Check if a file is a video.
     */
    public static function isVideo(File $file): bool
    {
        return \in_array($file->getMimeType(), Application::VIDEO_MIMES, true);
    }

    /**
     * Log error to console if CLI or logger.
     */
    private function error(string $message): void
    {
        $this->logger->error($message);

        if ($this->output) {
            $this->output->writeln("<error>{$message}</error>\n");
        }
    }

    /**
     * Log to console if CLI.
     */
    private function log(string $message, bool $overwrite = false): void
    {
        if ($this->section) {
            if ($overwrite) {
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
