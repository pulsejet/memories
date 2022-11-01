<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\IDBConnection;

const CTE_FOLDERS = // CTE to get all folders recursively in the given top folder
    'WITH RECURSIVE *PREFIX*cte_folders(fileid) AS (
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
        INNER JOIN *PREFIX*cte_folders c
            ON (f.parent = c.fileid
                AND f.mimetype = (SELECT `id` FROM `*PREFIX*mimetypes` WHERE `mimetype` = \'httpd/unix-directory\')
                AND f.fileid <> :excludedFolderId
            )
    )';

trait TimelineQueryDays
{
    protected IDBConnection $connection;

    /**
     * Get the days response from the database for the timeline.
     *
     * @param null|Folder $folder          The folder to get the days from
     * @param bool        $recursive       Whether to get the days recursively
     * @param bool        $archive         Whether to get the days only from the archive folder
     * @param array       $queryTransforms An array of query transforms to apply to the query
     *
     * @return array The days response
     */
    public function getDays(
        &$folder,
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
        ;
        $query = $this->joinFilecache($query, $folder, $recursive, $archive);

        // Group and sort by dayid
        $query->groupBy('m.dayid')
            ->orderBy('m.dayid', 'DESC')
        ;

        // Apply all transformations
        $this->applyAllTransforms($queryTransforms, $query, $uid);

        $cursor = $this->executeQueryWithCTEs($query);
        $rows = $cursor->fetchAll();
        $cursor->closeCursor();

        return $this->processDays($rows);
    }

    /**
     * Get the day response from the database for the timeline.
     *
     * @param null|Folder $folder          The folder to get the day from
     * @param string      $uid             The user id
     * @param int[]       $dayid           The day id
     * @param bool        $recursive       If the query should be recursive
     * @param bool        $archive         If the query should include only the archive folder
     * @param array       $queryTransforms The query transformations to apply
     * @param mixed       $day_ids
     *
     * @return array An array of day responses
     */
    public function getDay(
        &$folder,
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
        $query->select($fileid, 'm.isvideo', 'm.datetaken', 'm.dayid', 'm.w', 'm.h')
            ->from('memories', 'm')
        ;

        // JOIN with filecache for existing files
        $query = $this->joinFilecache($query, $folder, $recursive, $archive);
        $query->addSelect('f.etag', 'f.path', 'f.name AS basename');

        // JOIN with mimetypes to get the mimetype
        $query->join('f', 'mimetypes', 'mimetypes', $query->expr()->eq('f.mimetype', 'mimetypes.id'));
        $query->addSelect('mimetypes.mimetype');

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

        $cursor = $this->executeQueryWithCTEs($query);
        $rows = $cursor->fetchAll();
        $cursor->closeCursor();

        return $this->processDay($rows, $uid, $folder);
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
     * @param array       $day
     * @param string      $uid    User or blank if not logged in
     * @param null|Folder $folder
     */
    private function processDay(&$day, $uid, $folder)
    {
        $basePath = '#__#__#';
        $davPath = '';
        if (null !== $folder) {
            // No way to get the internal path from the folder
            $query = $this->connection->getQueryBuilder();
            $query->select('path')
                ->from('filecache')
                ->where($query->expr()->eq('fileid', $query->createNamedParameter($folder->getId(), IQueryBuilder::PARAM_INT)))
            ;
            $path = $query->executeQuery()->fetchOne();
            $basePath = $path ?: $basePath;

            // Get user facing path
            // getPath looks like /user/files/... but we want /files/user/...
            // Split at / and swap these
            // For public shares, we just give the relative path
            if (!empty($uid)) {
                $actualPath = $folder->getPath();
                $actualPath = explode('/', $actualPath);
                if (\count($actualPath) >= 3) {
                    $tmp = $actualPath[1];
                    $actualPath[1] = $actualPath[2];
                    $actualPath[2] = $tmp;
                    $davPath = implode('/', $actualPath);
                }
            }
        }

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

            // Check if path exists and starts with basePath and remove
            if (isset($row['path']) && !empty($row['path'])) {
                if (0 === strpos($row['path'], $basePath)) {
                    $row['filename'] = $davPath.substr($row['path'], \strlen($basePath));
                }
                unset($row['path']);
            }

            // All transform processing
            $this->processFace($row);
        }

        return $day;
    }

    private function executeQueryWithCTEs(IQueryBuilder &$query, string $psql = '')
    {
        $sql = empty($psql) ? $query->getSQL() : $psql;
        $params = $query->getParameters();
        $types = $query->getParameterTypes();

        // Add WITH clause if needed
        if (false !== strpos($sql, 'cte_folders')) {
            $sql = CTE_FOLDERS.' '.$sql;
        }

        return $this->connection->executeQuery($sql, $params, $types);
    }

    /**
     * Get all folders inside a top folder.
     */
    private function addSubfolderJoinParams(
        IQueryBuilder &$query,
        Folder &$folder,
        bool $archive
    ) {
        // Query parameters, set at the end
        $topFolderId = $folder->getId();
        $excludedFolderId = -1;

        /** @var Folder Archive folder if it exists */
        $archiveFolder = null;

        try {
            $archiveFolder = $folder->get('.archive/');
        } catch (\OCP\Files\NotFoundException $e) {
        }

        if (!$archive) {
            // Exclude archive folder
            if ($archiveFolder) {
                $excludedFolderId = $archiveFolder->getId();
            }
        } else {
            // Only include archive folder
            $topFolderId = $archiveFolder ? $archiveFolder->getId() : -1;
        }

        // Add query parameters
        $query->setParameter('topFolderId', $topFolderId, IQueryBuilder::PARAM_INT);
        $query->setParameter('excludedFolderId', $excludedFolderId, IQueryBuilder::PARAM_INT);
    }

    /**
     * Inner join with oc_filecache.
     *
     * @param IQueryBuilder $query     Query builder
     * @param null|Folder   $folder    Either the top folder or null for all
     * @param bool          $recursive Whether to get the days recursively
     * @param bool          $archive   Whether to get the days only from the archive folder
     */
    private function joinFilecache(
        IQueryBuilder &$query,
        &$folder,
        bool $recursive,
        bool $archive
    ) {
        // Join with memories
        $baseOp = $query->expr()->eq('f.fileid', 'm.fileid');
        if (null === $folder) {
            return $query->innerJoin('m', 'filecache', 'f', $baseOp);
        }

        // Filter by folder (recursive or otherwise)
        $pathOp = null;
        if ($recursive) {
            // Join with folders CTE
            $this->addSubfolderJoinParams($query, $folder, $archive);
            $query->innerJoin('f', 'cte_folders', 'cte_f', $query->expr()->eq('f.parent', 'cte_f.fileid'));
        } else {
            // If getting non-recursively folder only check for parent
            $pathOp = $query->expr()->eq('f.parent', $query->createNamedParameter($folder->getId(), IQueryBuilder::PARAM_INT));
        }

        return $query->innerJoin('m', 'filecache', 'f', $query->expr()->andX(
            $baseOp,
            $pathOp,
        ));
    }
}
