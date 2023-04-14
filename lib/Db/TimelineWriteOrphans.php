<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

trait TimelineWriteOrphans
{
    protected IDBConnection $connection;

    /**
     * Mark all files in the table as orphaned.
     *
     * @return int Number of rows affected
     */
    public function orphanAll(): int
    {
        $do = function (string $table) {
            $query = $this->connection->getQueryBuilder();
            $query->update($table)
                ->set('orphan', $query->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
            ;

            return $query->executeStatement();
        };

        return $do('memories') + $do('memories_livephoto');
    }
}
