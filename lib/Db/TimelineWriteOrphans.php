<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\IDBConnection;

trait TimelineWriteOrphans
{
    protected IDBConnection $connection;

    /**
     * Mark a file as not orphaned.
     */
    public function unorphan(File &$file)
    {
        $query = $this->connection->getQueryBuilder();
        $query->update('memories')
            ->set('orphan', $query->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
            ->where($query->expr()->eq('fileid', $query->createNamedParameter($file->getId(), IQueryBuilder::PARAM_INT)))
        ;
        $query->executeStatement();
    }

    /**
     * Mark all files in the table as orphaned.
     *
     * @return int Number of rows affected
     */
    public function orphanAll(): int
    {
        $query = $this->connection->getQueryBuilder();
        $query->update('memories')
            ->set('orphan', $query->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
        ;

        return $query->executeStatement();
    }

    /**
     * Remove all entries that are orphans.
     *
     * @return int Number of rows affected
     */
    public function removeOrphans(): int
    {
        $query = $this->connection->getQueryBuilder();
        $query->delete('memories')
            ->where($query->expr()->eq('orphan', $query->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
        ;

        return $query->executeStatement();
    }
}
