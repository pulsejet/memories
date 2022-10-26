<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\IDBConnection;

trait TimelineQueryDays
{
    protected IDBConnection $connection;

    /**
     * Get the days response from the database for the timeline.
     *
     * @param Folder $folder          The folder to get the days from
     * @param bool   $recursive       Whether to get the days recursively
     * @param bool   $archive         Whether to get the days only from the archive folder
     * @param array  $queryTransforms An array of query transforms to apply to the query
     *
     * @return array The days response
     */
    public function getDays(
        Folder &$folder,
        string $uid,
        bool $recursive,
        bool $archive,
        array $queryTransforms = []
    ): array {
        $query = $this->connection->getQueryBuilder();

        // Get all entries also present in filecache
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'), 'count');
        $query->select('m.dayid', $count)
            ->from('memories', 'm')
            ->innerJoin('m', 'filecache', 'f', $this->getFilecacheJoinQuery($query, $folder, $recursive, $archive))
        ;

        // Group and sort by dayid
        $query->groupBy('m.dayid')
            ->orderBy('m.dayid', 'DESC')
        ;

        // Apply all transformations
        $this->applyAllTransforms($queryTransforms, $query, $uid);

        $cursor = $query->executeQuery();
        $rows = $cursor->fetchAll();
        $cursor->closeCursor();

        return $this->processDays($rows);
    }

    /**
     * Get the day response from the database for the timeline.
     *
     * @param Folder $folder          The folder to get the day from
     * @param string $uid             The user id
     * @param int[]  $dayid           The day id
     * @param bool   $recursive       If the query should be recursive
     * @param bool   $archive         If the query should include only the archive folder
     * @param array  $queryTransforms The query transformations to apply
     * @param mixed  $day_ids
     *
     * @return array An array of day responses
     */
    public function getDay(
        Folder &$folder,
        string $uid,
        $day_ids,
        bool $recursive,
        bool $archive,
        array $queryTransforms = []
    ): array {
        $query = $this->connection->getQueryBuilder();

        // Get all entries also present in filecache
        $fileid = $query->createFunction('DISTINCT m.fileid');

        // We don't actually use m.datetaken here, but postgres
        // needs that all fields in ORDER BY are also in SELECT
        // when using DISTINCT on selected fields
        $query->select($fileid, 'f.etag', 'm.isvideo', 'vco.categoryid', 'm.datetaken', 'm.dayid', 'm.w', 'm.h')
            ->from('memories', 'm')
            ->innerJoin('m', 'filecache', 'f', $this->getFilecacheJoinQuery($query, $folder, $recursive, $archive))
        ;

        // Filter by dayid unless wildcard
        if (null !== $day_ids) {
            $query->andWhere($query->expr()->in('m.dayid', $query->createNamedParameter($day_ids, IQueryBuilder::PARAM_INT_ARRAY)));
        } else {
            // Limit wildcard to 100 results
            $query->setMaxResults(100);
        }

        // Add favorite field
        $this->addFavoriteTag($query, $uid);

        // Group and sort by date taken
        $query->orderBy('m.datetaken', 'DESC');
        $query->addOrderBy('m.fileid', 'DESC'); // tie-breaker

        // Apply all transformations
        $this->applyAllTransforms($queryTransforms, $query, $uid);

        $cursor = $query->executeQuery();
        $rows = $cursor->fetchAll();
        $cursor->closeCursor();

        return $this->processDay($rows);
    }

    /**
     * Process the days response.
     *
     * @param array $days
     */
    private function processDays(&$days)
    {
        foreach ($days as &$row) {
            $row['dayid'] = (int) $row['dayid'];
            $row['count'] = (int) $row['count'];
        }

        return $days;
    }

    /**
     * Process the single day response.
     *
     * @param array $day
     */
    private function processDay(&$day)
    {
        foreach ($day as &$row) {
            // We don't need date taken (see query builder)
            unset($row['datetaken']);

            // Convert field types
            $row['fileid'] = (int) $row['fileid'];
            $row['isvideo'] = (int) $row['isvideo'];
            $row['dayid'] = (int) $row['dayid'];
            $row['w'] = (int) $row['w'];
            $row['h'] = (int) $row['h'];
            if (!$row['isvideo']) {
                unset($row['isvideo']);
            }
            if ($row['categoryid']) {
                $row['isfavorite'] = 1;
            }
            unset($row['categoryid']);

            // All transform processing
            $this->processFace($row);
        }

        return $day;
    }

    /**
     * Get all folders inside a top folder.
     */
    private function getSubfolderIdsRecursive(
        IDBConnection &$conn,
        Folder &$folder,
        bool $archive
    ) {
        // CTE to get all folders recursively in the given top folder
        $cte =
            'WITH RECURSIVE cte_folders(fileid) AS (
                SELECT
                    f.fileid
                FROM
                    *PREFIX*filecache f
                WHERE
                    f.fileid = :topFolderId
                UNION ALL
                SELECT
                    f.fileid
                FROM
                    *PREFIX*filecache f
                INNER JOIN cte_folders c
                    ON (f.parent = c.fileid
                        AND f.mimetype = 2
                        AND f.fileid NOT IN (:excludedFolderIds)
                    )
                )
                SELECT
                    fileid
                FROM
                    cte_folders
                ';

        // Query parameters, set at the end
        $topFolderId = $folder->getId();
        $excludedFolderIds = [-1]; // cannot be empty

        /** @var Folder Archive folder if it exists */
        $archiveFolder = null;

        try {
            $archiveFolder = $folder->get('.archive/');
        } catch (\OCP\Files\NotFoundException $e) {
        }

        if (!$archive) {
            // Exclude archive folder
            if ($archiveFolder) {
                $excludedFolderIds[] = $archiveFolder->getId();
            }
        } else {
            // Only include archive folder
            $topFolderId = $archiveFolder ? $archiveFolder->getId() : -1;
        }

        return array_column($conn->executeQuery($cte, [
            'topFolderId' => $topFolderId,
            'excludedFolderIds' => $excludedFolderIds,
        ])->fetchAll(), 'fileid');
    }

    /**
     * Get the query for oc_filecache join.
     *
     * @param IQueryBuilder $query     Query builder
     * @param array|Folder  $folder    Either the top folder or array of folder Ids
     * @param bool          $recursive Whether to get the days recursively
     * @param bool          $archive   Whether to get the days only from the archive folder
     */
    private function getFilecacheJoinQuery(
        IQueryBuilder &$query,
        &$folder,
        bool $recursive,
        bool $archive
    ) {
        $pathQuery = null;
        if ($recursive) {
            // Get all subfolder Ids recursively
            $folderIds = [];
            if ($folder instanceof Folder) {
                $folderIds = $this->getSubfolderIdsRecursive($query->getConnection(), $folder, $archive);
            } else {
                $folderIds = $folder;
            }

            // Join with folder IDs
            $pathQuery = $query->expr()->in('f.parent', $query->createNamedParameter($folderIds, IQueryBuilder::PARAM_INT_ARRAY));
        } else {
            // If getting non-recursively folder only check for parent
            $pathQuery = $query->expr()->eq('f.parent', $query->createNamedParameter($folder->getId(), IQueryBuilder::PARAM_INT));
        }

        return $query->expr()->andX(
            $query->expr()->eq('f.fileid', 'm.fileid'),
            $pathQuery,
        );
    }
}
