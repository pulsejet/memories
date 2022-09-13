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
    private function makeQueryDays(
        IQueryBuilder &$query,
        $whereFilecache
    ) {
        // Get all entries also present in filecache
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'), 'count');
        $query->select('m.dayid', $count)
            ->from('memories', 'm')
            ->innerJoin('m', 'filecache', 'f',
                $query->expr()->andX(
                    $query->expr()->eq('f.fileid', 'm.fileid'),
                    $whereFilecache
                ));

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
        string $user,
        array $queryTransforms = []
    ): array {

        // Filter by path starting with timeline path
        $configPath = Exif::getPhotosPath($config, $user);
        $likeHome = Exif::removeExtraSlash("files/" . $configPath . "%");
        $likeExt = Exif::removeLeadingSlash(Exif::removeExtraSlash($configPath . "%"));

        $query = $this->connection->getQueryBuilder();
        $this->makeQueryDays($query, $query->expr()->orX(
            $query->expr()->like('f.path', $query->createNamedParameter($likeHome)),
            $query->expr()->like('f.path', $query->createNamedParameter($likeExt)),
        ));

        // Filter by user
        $query->andWhere($query->expr()->eq('m.uid', $query->createNamedParameter($user)));

        // Apply all transformations
        foreach ($queryTransforms as &$transform) {
            $transform($query, $user);
        }

        $rows = $query->executeQuery()->fetchAll();
        return $this->processDays($rows);
    }

    /**
     * Get the days response from the database for one folder
     * @param int $folderId
     */
    public function getDaysFolder(int $folderId) {
        $query = $this->connection->getQueryBuilder();
        $this->makeQueryDays($query, $query->expr()->orX(
            $query->expr()->eq('f.parent', $query->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)),
            $query->expr()->eq('f.fileid', $query->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)),
        ));

        $rows = $query->executeQuery()->fetchAll();
        return $this->processDays($rows);
    }
}
