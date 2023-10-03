<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\ClustersBackend;
use OCA\Memories\Exif;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

trait TimelineQueryDays
{
    use TimelineQueryCTE;

    protected IDBConnection $connection;

    /**
     * Get the days response from the database for the timeline.
     *
     * @param bool  $recursive       Whether to get the days recursively
     * @param bool  $archive         Whether to get the days only from the archive folder
     * @param array $queryTransforms An array of query transforms to apply to the query
     *
     * @return array The days response
     */
    public function getDays(
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

        // Group and sort by dayid
        $query->groupBy('m.dayid')
            ->orderBy('m.dayid', 'DESC')
        ;

        // Apply all transformations
        $this->applyAllTransforms($queryTransforms, $query, true);

        // JOIN with filecache for existing files
        $query = $this->joinFilecache($query, null, $recursive, $archive);

        // FETCH all days
        $rows = $this->executeQueryWithCTEs($query)->fetchAll();

        return $this->processDays($rows);
    }

    /**
     * Get the day response from the database for the timeline.
     *
     * @param int[] $day_ids         The day ids to fetch
     * @param bool  $recursive       If the query should be recursive
     * @param bool  $archive         If the query should include only the archive folder
     * @param array $queryTransforms The query transformations to apply
     *
     * @return array An array of day responses
     */
    public function getDay(
        ?array $day_ids,
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
        $query->select($fileid, ...TimelineQuery::TIMELINE_SELECT)
            ->from('memories', 'm')
        ;

        // JOIN with mimetypes to get the mimetype
        $query->join('f', 'mimetypes', 'mimetypes', $query->expr()->eq('f.mimetype', 'mimetypes.id'));

        // Filter by dayid unless wildcard
        if (null !== $day_ids) {
            $query->andWhere($query->expr()->in('m.dayid', $query->createNamedParameter($day_ids, IQueryBuilder::PARAM_INT_ARRAY)));
        } else {
            // Limit wildcard to 100 results
            $query->setMaxResults(100);
        }

        // Add favorite field
        $this->addFavoriteTag($query);

        // Group and sort by date taken
        $query->orderBy('m.datetaken', 'DESC');
        $query->addOrderBy('m.fileid', 'DESC'); // tie-breaker

        // Apply all transformations
        $this->applyAllTransforms($queryTransforms, $query, false);

        // JOIN with filecache for existing files
        $query = $this->joinFilecache($query, null, $recursive, $archive);

        // FETCH all photos in this day
        $day = $this->executeQueryWithCTEs($query)->fetchAll();

        // Post process the day in-place
        foreach ($day as &$photo) {
            $this->processDayPhoto($photo);
        }

        return $day;
    }

    public function executeQueryWithCTEs(IQueryBuilder $query, string $psql = '')
    {
        $sql = empty($psql) ? $query->getSQL() : $psql;
        $params = $query->getParameters();
        $types = $query->getParameterTypes();

        // Get SQL
        $CTE_SQL = \array_key_exists('cteFoldersArchive', $params) && $params['cteFoldersArchive']
            ? self::CTE_FOLDERS_ARCHIVE()
            : self::CTE_FOLDERS(false);

        // Add WITH clause if needed
        if (false !== strpos($sql, 'cte_folders')) {
            $sql = $CTE_SQL.' '.$sql;
        }

        return $this->connection->executeQuery($sql, $params, $types);
    }

    /**
     * Inner join with oc_filecache.
     *
     * @param IQueryBuilder $query     Query builder
     * @param TimelineRoot  $root      Either the top folder or null for all
     * @param bool          $recursive Whether to get the days recursively
     * @param bool          $archive   Whether to get the days only from the archive folder
     */
    public function joinFilecache(
        IQueryBuilder $query,
        ?TimelineRoot $root = null,
        bool $recursive = true,
        bool $archive = false
    ): IQueryBuilder {
        // This will throw if the root is illegally empty
        $root = $this->root($root);

        // Join with memories
        $baseOp = $query->expr()->eq('f.fileid', 'm.fileid');
        if ($root->isEmpty()) {
            return $query->innerJoin('m', 'filecache', 'f', $baseOp);
        }

        // Filter by folder (recursive or otherwise)
        $pathOp = null;
        if ($recursive) {
            // Join with folders CTE
            $this->addSubfolderJoinParams($query, $root, $archive);
            $query->innerJoin('f', 'cte_folders', 'cte_f', $query->expr()->eq('f.parent', 'cte_f.fileid'));
        } else {
            // If getting non-recursively folder only check for parent
            $pathOp = $query->expr()->eq('f.parent', $query->createNamedParameter($root->getOneId(), IQueryBuilder::PARAM_INT));
        }

        return $query->innerJoin('m', 'filecache', 'f', $query->expr()->andX(
            $baseOp,
            $pathOp,
        ));
    }

    /**
     * Process the days response.
     *
     * @param array $days
     */
    private function processDays($days)
    {
        foreach ($days as &$row) {
            $row['dayid'] = (int) $row['dayid'];
            $row['count'] = (int) $row['count'];
        }

        return $days;
    }

    /**
     * Process the single day response.
     */
    private function processDayPhoto(array &$row)
    {
        // Convert field types
        $row['fileid'] = (int) $row['fileid'];
        $row['isvideo'] = (int) $row['isvideo'];
        $row['video_duration'] = (int) $row['video_duration'];
        $row['dayid'] = (int) $row['dayid'];
        $row['w'] = (int) $row['w'];
        $row['h'] = (int) $row['h'];

        // Optional fields
        if (!$row['isvideo']) {
            unset($row['isvideo'], $row['video_duration']);
        }
        if (!$row['liveid']) {
            unset($row['liveid']);
        }

        // Favorite field, may not be present
        if (\array_key_exists('categoryid', $row) && $row['categoryid']) {
            $row['isfavorite'] = 1;
        }
        unset($row['categoryid']);

        // All cluster transformations
        ClustersBackend\Manager::applyDayPostTransforms($this->request, $row);

        // This field is only required due to the GROUP BY clause
        unset($row['datetaken']);

        // Calculate the AUID if we can
        if (\array_key_exists('epoch', $row) && \array_key_exists('size', $row)
           && ($epoch = (int) $row['epoch']) && ($size = (int) $row['size'])) {
            // compute AUID and discard epoch and size
            $row['auid'] = Exif::getAUID($epoch, $size);
        }
    }

    /**
     * Get all folders inside a top folder.
     */
    private function addSubfolderJoinParams(
        IQueryBuilder &$query,
        TimelineRoot &$root,
        bool $archive
    ) {
        // Add query parameters
        $query->setParameter('topFolderIds', $root->getIds(), IQueryBuilder::PARAM_INT_ARRAY);
        $query->setParameter('cteFoldersArchive', $archive, IQueryBuilder::PARAM_BOOL);
    }
}
