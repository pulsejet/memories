<?php
declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\Exif;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

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

    /** Get the base query builder for days */
    private function makeBaseQueryDays(
        IQueryBuilder &$query,
        string | null $user,
        $whereFilecache
    ) {
        // Get all entries also present in filecache
        $query->select('m.dayid', $query->func()->count('m.fileid', 'count'))
            ->from('memories', 'm')
            ->innerJoin('m', 'filecache', 'f',
                $query->expr()->andX(
                    $query->expr()->eq('f.fileid', 'm.fileid'),
                    $whereFilecache
                ));

        // Filter by user
        // This won't be used when looking at e.g. a shared folder
        if (!is_null($user)) {
            $query->where($query->expr()->eq('uid', $query->createNamedParameter($user)));
        }

        // Group and sort by dayid
        $query->groupBy('m.dayid')
              ->orderBy('m.dayid', 'DESC');
        return $query;
    }

    /**
     * Get the days response from the database for the timeline
     * @param IConfig $config
     * @param string $userId
     */
    public function getDays(
        IConfig &$config,
        string $user): array {

        $path = "files" . Exif::getPhotosPath($config, $user) . "%";
        $query = $this->connection->getQueryBuilder();
        $pathConstraint = $query->expr()->like('f.path', $query->createNamedParameter($path));
        $this->makeBaseQueryDays($query, $user, $pathConstraint);

        $rows = $query->executeQuery()->fetchAll();
        return $this->processDays($rows);
    }

    /**
     * Get the days response from the database for one folder
     * @param int $folderId
     */
    public function getDaysFolder(int $folderId) {
        $query = $this->connection->getQueryBuilder();
        $parentConstraint = $query->expr()->orX(
            $query->expr()->eq('f.parent', $query->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)),
            $query->expr()->eq('f.fileid', $query->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)),
        );
        $this->makeBaseQueryDays($query, null, $parentConstraint);

        $rows = $query->executeQuery()->fetchAll();
        return $this->processDays($rows);
    }
}
