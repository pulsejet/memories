<?php
declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\Exif;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

trait TimelineQueryDay {
    protected IDBConnection $connection;

    /**
     * Process the single day response
     * @param array $day
     */
    private function processDay(&$day) {
        foreach($day as &$row) {
            $row["fileid"] = intval($row["fileid"]);
            $row["isvideo"] = intval($row["isvideo"]);
            if (!$row["isvideo"]) {
                unset($row["isvideo"]);
            }
        }
        return $day;
    }

    /** Get the base query builder for day */
    private function makeBaseQueryDay(
        IQueryBuilder &$query,
        string | null $user,
        $whereFilecache,
        int $dayid
    ) {
        // Get all entries also present in filecache
        $query->select('m.fileid', 'f.etag', 'm.isvideo')
            ->from('memories', 'm')
            ->innerJoin('m', 'filecache', 'f',
                $query->expr()->andX(
                    $query->expr()->eq('f.fileid', 'm.fileid'),
                    $whereFilecache
                ))
            ->where($query->expr()->eq('m.dayid', $query->createNamedParameter($dayid, IQueryBuilder::PARAM_INT)));

        // Filter by user
        // This won't be used when looking at e.g. a shared folder
        if (!is_null($user)) {
            $query->andWhere($query->expr()->eq('uid', $query->createNamedParameter($user)));
        }

        // Group and sort by date taken
        $query->orderBy('m.datetaken', 'DESC');
        return $query;
    }

    /**
     * Get a day response from the database for the timeline
     * @param IConfig $config
     * @param string $userId
     * @param int $dayId
     */
    public function getDay(
        IConfig &$config,
        string $user,
        int $dayId): array {

        $path = "files" . Exif::getPhotosPath($config, $user) . "%";
        $query = $this->connection->getQueryBuilder();
        $pathConstraint = $query->expr()->like('f.path', $query->createNamedParameter($path));
        $this->makeBaseQueryDay($query, $user, $pathConstraint, $dayId);

        $rows = $query->executeQuery()->fetchAll();
        return $this->processDay($rows);
    }

    /**
     * Get a day response from the database for one folder
     * @param int $folderId
     * @param int $dayId
     */
    public function getDayFolder(
        int $folderId,
        int $dayId): array {

        $query = $this->connection->getQueryBuilder();
        $parentConstraint = $query->expr()->orX(
            $query->expr()->eq('f.parent', $query->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)),
            $query->expr()->eq('f.fileid', $query->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)),
        );
        $this->makeBaseQueryDay($query, null, $parentConstraint, $dayId);

        $rows = $query->executeQuery()->fetchAll();
        return $this->processDay($rows);
    }
}