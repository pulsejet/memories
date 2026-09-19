<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class LensFolders
{
    use TimelineQueryCTE;

    public function __construct(
        protected IDBConnection $connection,
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
        $query->setParameter('topFolderIds', array_values($topFolderIds), IQueryBuilder::PARAM_INT_ARRAY);

        $rows = $this->executeQueryWithCTEs($query)->fetchAll();

        return array_map(static fn (mixed $row) => (int) $row['fileid'], $rows);
    }
}
