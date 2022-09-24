<?php
declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;

trait TimelineQueryDays {
    protected IDBConnection $connection;

    /**
     * Process the days response
     * @param array $days
     */
    private function processDays(&$days) {
        foreach($days as &$row) {
            $row["dayid"] = intval($row["dayid"]);
            $row["count"] = intval($row["count"]);
        }
        return $days;
    }

        /**
     * Process the single day response
     * @param array $day
     */
    private function processDay(&$day) {
        foreach($day as &$row) {
            // We don't need date taken (see query builder)
            unset($row['datetaken']);

            // Convert field types
            $row["fileid"] = intval($row["fileid"]);
            $row["isvideo"] = intval($row["isvideo"]);
            if (!$row["isvideo"]) {
                unset($row["isvideo"]);
            }
            if ($row["categoryid"]) {
                $row["isfavorite"] = 1;
            }
            unset($row["categoryid"]);
        }
        return $day;
    }

    /** Get the query for oc_filecache join */
    private function getFilecacheJoinQuery(IQueryBuilder &$query, Folder &$folder, bool $recursive) {
        // Subquery to get storage and path
        $subQuery = $query->getConnection()->getQueryBuilder();
        $finfo = $subQuery->select('path', 'storage')->from('filecache')->where(
            $subQuery->expr()->eq('fileid', $subQuery->createNamedParameter($folder->getId())),
        )->executeQuery()->fetch();
        if (empty($finfo)) {
            throw new \Exception("Folder not found");
        }

        $pathQuery = null;
        if ($recursive) {
            // Filter by path for recursive query
            $likePath = $finfo["path"];
            if (!empty($likePath)) {
                $likePath .= '/';
            }
            $likePath = $likePath  . '%';
            $pathQuery = $query->expr()->like('f.path', $query->createNamedParameter($likePath));
        } else {
            // If getting non-recursively folder only check for parent
            $pathQuery = $query->expr()->eq('f.parent', $query->createNamedParameter($folder->getId(), IQueryBuilder::PARAM_INT));
        }

        return $query->expr()->andX(
            $query->expr()->eq('f.fileid', 'm.fileid'),
            $query->expr()->in('f.storage', $query->createNamedParameter($finfo["storage"])),
            $pathQuery,
        );
    }

    /**
     * Get the days response from the database for the timeline
     * @param string $userId
     */
    public function getDays(
        Folder &$folder,
        string $uid,
        bool $recursive,
        array $queryTransforms = []
    ): array {
        $query = $this->connection->getQueryBuilder();

        // Get all entries also present in filecache
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'), 'count');
        $query->select('m.dayid', $count)
            ->from('memories', 'm')
            ->innerJoin('m', 'filecache', 'f', $this->getFilecacheJoinQuery($query, $folder, $recursive));

        // Group and sort by dayid
        $query->groupBy('m.dayid')
              ->orderBy('m.dayid', 'DESC');

        // Apply all transformations
        foreach ($queryTransforms as &$transform) {
            $transform($query, $uid);
        }

        $rows = $query->executeQuery()->fetchAll();
        return $this->processDays($rows);
    }

        /**
     * Get the days response from the database for the timeline
     * @param string $userId
     */
    public function getDay(
        Folder &$folder,
        string $uid,
        int $dayid,
        bool $recursive,
        array $queryTransforms = []
    ): array {
        $query = $this->connection->getQueryBuilder();

        // Get all entries also present in filecache
        $fileid = $query->createFunction('DISTINCT m.fileid');

        // We don't actually use m.datetaken here, but postgres
        // needs that all fields in ORDER BY are also in SELECT
        // when using DISTINCT on selected fields
        $query->select($fileid, 'f.etag', 'm.isvideo', 'vco.categoryid', 'm.datetaken')
            ->from('memories', 'm')
            ->innerJoin('m', 'filecache', 'f', $this->getFilecacheJoinQuery($query, $folder, $recursive))
            ->andWhere($query->expr()->eq('m.dayid', $query->createNamedParameter($dayid, IQueryBuilder::PARAM_INT)));

        // Add favorite field
        $this->addFavoriteTag($query, $uid);

        // Group and sort by date taken
        $query->orderBy('m.datetaken', 'DESC');

        // Apply all transformations
        foreach ($queryTransforms as &$transform) {
            $transform($query, $uid);
        }

        $rows = $query->executeQuery()->fetchAll();
        return $this->processDay($rows);
    }
}
