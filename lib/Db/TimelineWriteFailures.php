<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\IDBConnection;

trait TimelineWriteFailures
{
    protected IDBConnection $connection;

    /**
     * Mark a file as failed indexing.
     * The file will not be re-indexed until it changes.
     *
     * @param File   $file   The file that failed indexing
     * @param string $reason The reason for the failure
     */
    public function markFailed(File $file, string $reason): void
    {
        // Add file path to reason
        $reason .= " ({$file->getPath()})";

        // Remove all previous failures for this file
        Util::transaction(function () use ($file, $reason): void {
            $this->clearFailures($file);

            // Add the failure to the database
            $query = $this->connection->getQueryBuilder();
            $query->insert('memories_failures')
                ->values([
                    'fileid' => $query->createNamedParameter($file->getId(), IQueryBuilder::PARAM_INT),
                    'mtime' => $query->createNamedParameter($file->getMtime(), IQueryBuilder::PARAM_INT),
                    'reason' => $query->createNamedParameter($reason, IQueryBuilder::PARAM_STR),
                ])
                ->executeStatement()
            ;
        });
    }

    /**
     * Mark a file as successfully indexed.
     * The entry will be removed from the failures table.
     *
     * @param File $file The file that was successfully indexed
     */
    public function clearFailures(File $file): void
    {
        $query = $this->connection->getQueryBuilder();
        $query->delete('memories_failures')
            ->where($query->expr()->eq('fileid', $query->createNamedParameter($file->getId(), IQueryBuilder::PARAM_INT)))
            ->executeStatement()
        ;
    }

    /**
     * Get the count of failed files.
     */
    public function countFailures(): int
    {
        $query = $this->connection->getQueryBuilder();
        $query->select($query->func()->count('fileid'))
            ->from('memories_failures')
        ;

        return (int) $query->executeQuery()->fetchOne();
    }

    /**
     * Get the list of failures.
     */
    public function listFailures(): array
    {
        return $this->connection->getQueryBuilder()
            ->select('*')
            ->from('memories_failures')
            ->executeQuery()
            ->fetchAll()
        ;
    }

    /**
     * Clear all failures from the database.
     */
    public function clearAllFailures(): void
    {
        // Delete all entries and reset autoincrement counter
        $this->connection->executeStatement(
            $this->connection->getDatabasePlatform()->getTruncateTableSQL('*PREFIX*memories_failures', false),
        );
    }
}
