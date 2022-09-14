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
            // We don't need date taken (see query builder)
            unset($row['date_taken']);

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

    /** Get the base query builder for day */
    private function makeQueryDay(
        IQueryBuilder &$query,
        int $dayid,
        string $user,
        $whereFilecache
    ) {
        // Get all entries also present in filecache
        $fileid = $query->createFunction('DISTINCT m.fileid');

        // We don't actually use m.datetaken here, but postgres
        // needs that all fields in ORDER BY are also in SELECT
        // when using DISTINCT on selected fields
        $query->select($fileid, 'f.etag', 'm.isvideo', 'vco.categoryid', 'm.datetaken')
            ->from('memories', 'm')
            ->innerJoin('m', 'filecache', 'f',
                $query->expr()->andX(
                    $query->expr()->eq('f.fileid', 'm.fileid'),
                    $whereFilecache
                ))
            ->andWhere($query->expr()->eq('m.dayid', $query->createNamedParameter($dayid, IQueryBuilder::PARAM_INT)));

        // Add favorite field
        $this->addFavoriteTag($query, $user);

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
        int $dayId,
        array $queryTransforms = []
    ): array {
        // Filter by path starting with timeline path
        $configPath = Exif::getPhotosPath($config, $user);
        $likeHome = Exif::removeExtraSlash("files/" . $configPath . "%");
        $likeExt = Exif::removeLeadingSlash(Exif::removeExtraSlash($configPath . "%"));

        $query = $this->connection->getQueryBuilder();
        $this->makeQueryDay($query, $dayId, $user, $query->expr()->orX(
            $query->expr()->like('f.path', $query->createNamedParameter($likeHome)),
            $query->expr()->like('f.path', $query->createNamedParameter($likeExt)),
        ));

        // Filter by UID
        $query->andWhere($query->expr()->eq('m.uid', $query->createNamedParameter($user)));

        // Apply all transformations
        foreach ($queryTransforms as &$transform) {
            $transform($query, $user);
        }

        $rows = $query->executeQuery()->fetchAll();
        return $this->processDay($rows);
    }

    /**
     * Get a day response from the database for one folder
     * @param int $folderId
     * @param int $dayId
     */
    public function getDayFolder(
        string $user,
        int $folderId,
        int $dayId
    ): array {
        $query = $this->connection->getQueryBuilder();
        $this->makeQueryDay($query, $dayId, $user, $query->expr()->orX(
            $query->expr()->eq('f.parent', $query->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)),
            $query->expr()->eq('f.fileid', $query->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)),
        ));

        $rows = $query->executeQuery()->fetchAll();
        return $this->processDay($rows);
    }
}