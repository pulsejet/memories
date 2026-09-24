<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\Service\MIME;
use OCA\Memories\Settings\SystemConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
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
        int $batchSize = 1000,
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
        $getFilter = function (string $table, bool $notOrphaned) use ($query): IQueryFunction {
            $clause = $this->connection->getQueryBuilder();
            $clause->select($clause->expr()->literal(1))
                ->from($table, 'a')
                ->andWhere($clause->expr()->eq('f.fileid', 'a.fileid'))
                ->andWhere($clause->expr()->eq('f.mtime', 'a.mtime'))
            ;

            if ($notOrphaned) {
                $clause->andWhere($clause->expr()->eq('a.orphan', $clause->expr()->literal(0)));
            }

            return SQL::notExists($query, $clause);
        };
        $query->andWhere($getFilter('memories', true));
        $query->andWhere($getFilter('memories_livephoto', true));
        $query->andWhere($getFilter('memories_failures', false));

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
}
