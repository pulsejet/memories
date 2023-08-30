<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

trait TimelineWriteOrphans
{
    protected IDBConnection $connection;

    /**
     * Mark all or some files in the table as (un)orphaned.
     *
     * @param bool  $value    True to mark as orphaned, false to mark as un-orphaned
     * @param int[] $fileIds  List of file IDs to mark, or empty to mark all files
     * @param bool  $onlyMain Only mark the main file, not the live photo
     *
     * @return int Number of rows affected
     */
    public function orphanAll(bool $value = true, ?array $fileIds = null, bool $onlyMain = false): int
    {
        $do = function (string $table) use ($value, $fileIds) {
            $query = $this->connection->getQueryBuilder();
            $query->update($table)
                ->set('orphan', $query->createNamedParameter($value, IQueryBuilder::PARAM_BOOL))
            ;

            if ($fileIds) {
                $query->where($query->expr()->in('fileid', $query->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));
            }

            return $query->executeStatement();
        };

        $count = $do('memories');

        if ($onlyMain) {
            return $count;
        }

        return $count + $do('memories_livephoto');
    }

    /**
     * Orphan and run an update on all files.
     *
     * @param array    $fields   list of fields to select
     * @param int      $txnSize  number of rows to process in a single transaction
     * @param \Closure $callback will be passed each row
     */
    public function orphanAndRun(array $fields, int $txnSize, \Closure $callback)
    {
        // Orphan all files. This means if we are interrupted,
        // it will lead to a re-index of the whole library!
        $this->orphanAll(true, null, true);

        while (\count($orphans = $this->getSomeOrphans($txnSize, $fields))) {
            $this->connection->beginTransaction();

            foreach ($orphans as $row) {
                $callback($row);
            }

            // Mark all files as not orphaned.
            $fileIds = array_map(static fn ($row) => $row['fileid'], $orphans);
            $this->orphanAll(false, $fileIds, true);

            $this->connection->commit();
        }
    }

    /**
     * Get a list of orphaned files.
     */
    protected function getSomeOrphans(int $count, array $fields): array
    {
        $query = $this->connection->getQueryBuilder();
        $query->select(...$fields)
            ->from('memories')
            ->where($query->expr()->eq('orphan', $query->expr()->literal(1)))
            ->setMaxResults($count)
        ;

        return $query->executeQuery()->fetchAll();
    }
}
