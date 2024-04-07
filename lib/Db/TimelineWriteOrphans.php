<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

trait TimelineWriteOrphans
{
    protected IDBConnection $connection;

    /**
     * Mark all or some files in the table as (un)orphaned.
     *
     * @param bool  $value     True to mark as orphaned, false to mark as un-orphaned
     * @param int[] $fileIds   List of file IDs to mark, or empty to mark all files
     * @param bool  $livephoto Also include live photos in the update
     */
    public function orphanAll(bool $value = true, ?array $fileIds = null, bool $livephoto = true): void
    {
        // Helper function to update a table.
        $update = fn (string $table): int => Util::transaction(function () use ($table, $value, $fileIds): int {
            $query = $this->connection->getQueryBuilder();
            $query->update($table)
                ->set('orphan', $query->createNamedParameter($value, IQueryBuilder::PARAM_BOOL))
            ;

            if ($fileIds) {
                $query->where($query->expr()->in('fileid', $query->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));
            }

            return $query->executeStatement();
        });

        // Mark all files as orphaned.
        $update('memories');

        // Mark all live photos as orphaned.
        if ($livephoto) {
            $update('memories_livephoto');
        }

        // Unorphan all files on abort if we can
        if ($value) {
            Util::registerInterruptHandler('orphanAll', function () {
                // If we are in a transaction, abort it.
                if ($this->connection->inTransaction()) {
                    $this->connection->rollBack();
                }

                // Unorphan all files.
                $this->orphanAll(false);
            });
        }
    }

    /**
     * Orphan and run an update on all files.
     *
     * @param array                 $fields   list of fields to select
     * @param int                   $txnSize  number of rows to process in a single transaction
     * @param \Closure(array): void $callback will be passed each row
     */
    public function orphanAndRun(array $fields, int $txnSize, \Closure $callback): void
    {
        // Orphan all files. This means if we are interrupted,
        // it will lead to a re-index of the whole library!
        $this->orphanAll(true, null, false);

        while (\count($orphans = $this->getSomeOrphans($txnSize, $fields))) {
            Util::transaction(function () use ($callback, $orphans): void {
                foreach ($orphans as $row) {
                    $callback($row);
                }

                // Mark all files as not orphaned.
                $fileIds = array_map(static fn ($row): int => (int) $row['fileid'], $orphans);
                $this->orphanAll(false, $fileIds, false);
            });
        }
    }

    /**
     * Get a list of orphaned files.
     *
     * @param int      $count  max number of rows to return
     * @param string[] $fields list of fields to select
     */
    private function getSomeOrphans(int $count, array $fields): array
    {
        return Util::transaction(function () use ($count, $fields): array {
            $query = $this->connection->getQueryBuilder();

            return $query->select(...$fields)
                ->from('memories')
                ->where($query->expr()->eq('orphan', $query->expr()->literal(1)))
                ->setMaxResults($count)
                ->executeQuery()
                ->fetchAll()
            ;
        });
    }
}
