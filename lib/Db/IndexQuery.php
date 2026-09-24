<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\Service\MIME;
use OCA\Memories\Settings\SystemConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class IndexQuery
{
    public function __construct(
        private IDBConnection $connection,
        private TimelineQuery $tq,
        private SystemConfig $systemConfig,
        private MIME $mime,
    ) {}

    /**
     * Stream candidate fileids under the given top folder ids in batches.
     *
     * Crawls hidden folders; blocklisted folders prune whole subtrees.
     * Only unindexed files with supported mimetypes and nonzero size qualify.
     *
     * @param int[] $topFolderIds top folder fileids
     *
     * @return \Generator<int, int[]> batches of fileids
     */
    public function getCandidateBatches(
        array $topFolderIds,
        int $batchSize = 200,
    ): \Generator {
        $mimes = $this->mime->getMimeList();
        if ([] === $topFolderIds || [] === $mimes) {
            return;
        }

        /** @var string[] $blocklist */
        $blocklist = $this->systemConfig->get('memories.index.folder.blocklist');

        while (true) {
            $batch = $this->fetchCandidateBatch($topFolderIds, $mimes, $blocklist, $batchSize);
            if ([] === $batch) {
                return;
            }

            yield $batch;
        }
    }

    /**
     * Check if a file is indexed (or failed).
     *
     * @param int $fileId fileid
     * @param int $mtime  file mtime
     */
    public function isIndexed(int $fileId, int $mtime): bool
    {
        $query = $this->connection->getQueryBuilder();
        $query->select($query->expr()->literal(1))
            ->from('filecache', 'f')
            ->where($query->expr()->eq('f.fileid', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->eq('f.mtime', $query->createNamedParameter($mtime, IQueryBuilder::PARAM_INT)))
        ;
        $query = $this->filterNonIndexed($query);

        return false === $query->executeQuery()->fetchOne();
    }

    /**
     * Fetch up to $batchSize candidate fileids.
     *
     * @param int[]    $topFolderIds top folder fileids
     * @param string[] $mimes        supported mimetypes
     * @param string[] $blocklist    folder name LIKE patterns to prune
     *
     * @return int[] fileids in no particular order
     */
    private function fetchCandidateBatch(
        array $topFolderIds,
        array $mimes,
        array $blocklist,
        int $batchSize,
    ): array {
        $query = $this->connection->getQueryBuilder();
        $query->select('f.fileid')->from('filecache', 'f');

        // Only files with supported mimetypes (materialized once) and nonzero size
        $query->andWhere($query->expr()->gt('f.size', $query->expr()->literal(0)));

        $mimeParam = $query->createNamedParameter($mimes, IQueryBuilder::PARAM_STR_ARRAY);
        $mimeQuery = $this->connection->getQueryBuilder();
        $mimeQuery->select('m.id')
            ->from('mimetypes', 'm')
            ->where($mimeQuery->expr()->in('m.mimetype', $mimeParam))
        ;
        $mimeQuery = SQL::materialize($mimeQuery, 'mm');
        $query->andWhere($query->expr()->in('f.mimetype', SQL::subquery($query, $mimeQuery)));

        // Only files in one of the crawled folders
        $inFolders = $this->connection->getQueryBuilder();
        $inFolders->select($inFolders->expr()->literal(1))
            ->from('cte_folders', 'cte_f')
            ->where($inFolders->expr()->eq('f.parent', 'cte_f.fileid'))
        ;
        $query->andWhere(SQL::exists($query, $inFolders));

        // Filter out files that are already indexed or failed
        $this->filterNonIndexed($query);

        // Unordered fetch: indexed rows drop out via NOT EXISTS,
        // so refetching makes progress until an empty batch
        $query->setMaxResults($batchSize);

        CTEParams::setTopFolderIds($query, $topFolderIds);
        CTEParams::setIncludeHidden($query, true);
        CTEParams::setFolderNameBlocklist($query, $blocklist);

        $batch = [];
        foreach ($this->tq->executeQueryWithCTEs($query)->fetchAll() as $row) {
            $batch[] = (int) $row['fileid'];
        }

        return $batch;
    }

    private function filterNonIndexed(IQueryBuilder $query): IQueryBuilder
    {
        // Whether the orphan flag applies per table
        $tables = [
            'memories' => true,
            'memories_livephoto' => true,
            'memories_failures' => false,
        ];

        foreach ($tables as $table => $checkOrphan) {
            $clause = $this->connection->getQueryBuilder();

            $clause->select($clause->expr()->literal(1))
                ->from($table, 'a')
                ->andWhere($clause->expr()->eq('f.fileid', 'a.fileid'))
                ->andWhere($clause->expr()->eq('f.mtime', 'a.mtime'))
            ;

            if ($checkOrphan) {
                $clause->andWhere($clause->expr()->eq('a.orphan', $clause->expr()->literal(0)));
            }

            $query->andWhere(SQL::notExists($query, $clause));
        }

        return $query;
    }
}
