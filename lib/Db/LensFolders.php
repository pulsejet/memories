<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\IDBConnection;

final class LensFolders
{
    public function __construct(
        private IDBConnection $connection,
        private TimelineQuery $tq,
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
}
