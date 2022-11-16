<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\IDBConnection;

const CTE_FOLDERS = // CTE to get all folders recursively in the given top folders excluding archive
    'WITH RECURSIVE *PREFIX*cte_folders(fileid, rootid) AS (
        SELECT
            f.fileid,
            f.fileid AS rootid
        FROM
            *PREFIX*filecache f
        WHERE
            f.fileid IN (:topFolderIds)
        UNION ALL
        SELECT
            f.fileid,
            c.rootid
        FROM
            *PREFIX*filecache f
        INNER JOIN *PREFIX*cte_folders c
            ON (f.parent = c.fileid
                AND f.mimetype = (SELECT `id` FROM `*PREFIX*mimetypes` WHERE `mimetype` = \'httpd/unix-directory\')
                AND f.name <> \'.archive\'
            )
    )';

const CTE_FOLDERS_ARCHIVE = // CTE to get all archive folders recursively in the given top folders
    'WITH RECURSIVE *PREFIX*cte_folders_all(fileid, name) AS (
        SELECT
            f.fileid, f.name
        FROM
            *PREFIX*filecache f
        WHERE
            f.fileid IN (:topFolderIds)
        UNION ALL
        SELECT
            f.fileid, f.name
        FROM
            *PREFIX*filecache f
        INNER JOIN *PREFIX*cte_folders_all c
            ON (f.parent = c.fileid
                AND f.mimetype = (SELECT `id` FROM `*PREFIX*mimetypes` WHERE `mimetype` = \'httpd/unix-directory\')
            )
    ), *PREFIX*cte_folders(fileid) AS (
        SELECT
            f.fileid
        FROM
            *PREFIX*cte_folders_all f
        WHERE
            f.name = \'.archive\'
        UNION ALL
        SELECT
            f.fileid
        FROM
            *PREFIX*filecache f
        INNER JOIN *PREFIX*cte_folders c
            ON (f.parent = c.fileid)
    )';

trait TimelineQueryDays
{
    protected IDBConnection $connection;

    /** Map of rootid => mount point */
    private $topFolderPaths = [];

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
     * @param int[]       $day_ids         The day ids to fetch
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
        $query->select($fileid, 'm.isvideo', 'm.video_duration', 'm.datetaken', 'm.dayid', 'm.w', 'm.h')
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
        /**
         * Path entry in database for folder.
         * We need to splice this from the start of the file path.
         */
        $internalPaths = [];

        /**
         * DAV paths for the folders.
         * We need to prefix this to the start of the file path.
         */
        $davPaths = [];

        /**
         * The root folder id for the folder.
         * We fallback to this if rootid is not found
         */
        $defaultRootId = 0;

        if (null !== $folder) {
            // Get root id of the top folder
            $defaultRootId = $folder->getId();

            // No way to get the internal path from the folder
            $query = $this->connection->getQueryBuilder();
            $query->select('fileid', 'path')
                ->from('filecache')
                ->where($query->expr()->in('fileid', $query->createNamedParameter(array_keys($this->topFolderPaths), IQueryBuilder::PARAM_INT_ARRAY)))
            ;
            $paths = $query->executeQuery()->fetchAll();
            foreach ($paths as &$path) {
                $fileid = (int) $path['fileid'];
                $internalPaths[$fileid] = $path['path'];

                // Get DAV path.
                // getPath looks like /user/files/... but we want /files/user/...
                // Split at / and swap these
                // For public shares, we just give the relative path
                if (!empty($uid) && ($actualPath = $this->topFolderPaths[$fileid])) {
                    $actualPath = explode('/', $actualPath);
                    if (\count($actualPath) >= 3) {
                        $tmp = $actualPath[1];
                        $actualPath[1] = $actualPath[2];
                        $actualPath[2] = $tmp;
                        $davPaths[$fileid] = implode('/', $actualPath);
                    }
                }
            }
        }

        foreach ($day as &$row) {
            // We don't need date taken (see query builder)
            unset($row['datetaken']);

            // Convert field types
            $row['fileid'] = (int) $row['fileid'];
            $row['isvideo'] = (int) $row['isvideo'];
            $row['video_duration'] = (int) $row['video_duration'];
            $row['dayid'] = (int) $row['dayid'];
            $row['w'] = (int) $row['w'];
            $row['h'] = (int) $row['h'];
            if (!$row['isvideo']) {
                unset($row['isvideo'], $row['video_duration']);
            }
            if ($row['categoryid']) {
                $row['isfavorite'] = 1;
            }
            unset($row['categoryid']);

            // Check if path exists and starts with basePath and remove
            if (isset($row['path']) && !empty($row['path'])) {
                $rootId = $row['rootid'] ?: $defaultRootId;
                $basePath = $internalPaths[$rootId] ?: '#__#';
                $davPath = $davPaths[$rootId] ?: '';

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

        // Get SQL
        $CTE_SQL = $params['cteFoldersArchive'] ? CTE_FOLDERS_ARCHIVE : CTE_FOLDERS;

        // Add WITH clause if needed
        if (false !== strpos($sql, 'cte_folders')) {
            $sql = $CTE_SQL.' '.$sql;
        }

        return $this->connection->executeQuery($sql, $params, $types);
    }

    /**
     * Get all folders inside a top folder.
     */
    private function addSubfolderJoinParams(
        IQueryBuilder &$query,
        bool $archive
    ) {
        // Add query parameters
        $query->setParameter('topFolderIds', array_keys($this->topFolderPaths), IQueryBuilder::PARAM_INT_ARRAY);
        $query->setParameter('cteFoldersArchive', $archive, IQueryBuilder::PARAM_BOOL);
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

        // Create top folders paths for later processing
        $this->topFolderPaths = [];
        $this->topFolderPaths[$folder->getId()] = $folder->getPath();

        // Filter by folder (recursive or otherwise)
        $pathOp = null;
        if ($recursive) {
            // Add mountpoints recursively
            $this->mounts = \OC\Files\Filesystem::getMountManager()->findIn($folder->getPath());
            foreach ($this->mounts as &$mount) {
                $id = $mount->getStorageRootId();
                $path = $mount->getMountPoint();
                $this->topFolderPaths[$id] = $path;
            }

            // Join with folders CTE
            $this->addSubfolderJoinParams($query, $archive);
            $query->innerJoin('f', 'cte_folders', 'cte_f', $query->expr()->eq('f.parent', 'cte_f.fileid'));
            $query->addSelect('cte_f.rootid');
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
