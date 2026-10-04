<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;

final class LensFolders
{
    private const SCAN_BATCH_SIZE = 1000;
    private const SCAN_CURSOR_KEY = 'lens_scan_cursor';
    private const SCAN_LOCK_KEY = 'memories/lens/scan';

    public function __construct(
        private IDBConnection $connection,
        private TimelineQuery $tq,
        private IAppConfig $appConfig,
        private ILockingProvider $lockingProvider,
    ) {}

    /**
     * Get all nested folder fileids under the given top folder ids.
     *
     * Hidden folders are excluded; .nomedia/.nomemories respected by the CTE.
     *
     * @param int[] $topFolderIds top folder fileids
     *
     * @return int[] folder fileids including the top folders
     */
    public function getFolderIds(array $topFolderIds): array
    {
        if ([] === $topFolderIds) {
            return [];
        }

        $query = $this->connection->getQueryBuilder();
        $query->select('cte_f.fileid')->from('cte_folders', 'cte_f');
        CTEParams::setTopFolderIds($query, $topFolderIds);

        $ids = $this->tq->executeQueryWithCTEs($query)->fetchFirstColumn();

        return array_map(static fn (mixed $id) => (int) $id, $ids);
    }

    /**
     * Allocate the next inclusive fileid range; a null end covers the remaining tail.
     *
     * @return array{start: int, end: ?int, done: bool, files: list<array{fileid: int, isvideo: bool, parentid: int, mtime: int, etag: string, owner: string}>}
     */
    public function nextScanBatch(): array
    {
        // Hold the lock until the batch is allocated and the cursor is saved.
        $this->lockingProvider->acquireLock(self::SCAN_LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);

        try {
            // Another request may have advanced the cursor since app config was loaded.
            $this->appConfig->clearCache();
            $cursor = $this->appConfig->getValueInt(Application::APPNAME, self::SCAN_CURSOR_KEY);

            $files = $this->fetchScanBatch($cursor);
            $done = \count($files) < self::SCAN_BATCH_SIZE;

            // At EOF, include the unbounded tail so Lens can clean up deleted files.
            $end = $done ? null : $files[self::SCAN_BATCH_SIZE - 1]['fileid'];

            // Restart from the beginning on the next call after EOF.
            $this->appConfig->setValueInt(Application::APPNAME, self::SCAN_CURSOR_KEY, $end ?? 0);

            return [
                'start' => $cursor + 1,
                'end' => $end,
                'done' => $done,
                'files' => $files,
            ];
        } finally {
            $this->lockingProvider->releaseLock(self::SCAN_LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    /**
     * @return list<array{fileid: int, isvideo: bool, parentid: int, mtime: int, etag: string, owner: string}>
     */
    private function fetchScanBatch(int $cursor): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('m.fileid', 'm.isvideo', 'f.parent', 'm.mtime', 'f.etag')
            ->selectAlias('s.id', 'owner')
            ->from('memories', 'm')
            ->innerJoin('m', 'filecache', 'f', $qb->expr()->eq('m.fileid', 'f.fileid'))
            ->innerJoin('f', 'storages', 's', $qb->expr()->eq('f.storage', 's.numeric_id'))
            ->where($qb->expr()->gt('m.fileid', $qb->createNamedParameter($cursor, IQueryBuilder::PARAM_INT)))
            ->orderBy('m.fileid', 'ASC')
            ->setMaxResults(self::SCAN_BATCH_SIZE)
        ;

        return array_map(static fn (array $row): array => [
            'fileid' => (int) $row['fileid'],
            'isvideo' => (bool) $row['isvideo'],
            'parentid' => (int) $row['parent'],
            'mtime' => (int) $row['mtime'],
            'etag' => (string) $row['etag'],
            'owner' => (string) $row['owner'],
        ], $qb->executeQuery()->fetchAllAssociative());
    }
}
