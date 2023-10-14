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
     * @param int[] $dayIds          The day ids to fetch
     * @param bool  $recursive       If the query should be recursive
     * @param bool  $archive         If the query should include only the archive folder
     * @param bool  $hidden          If the query should include hidden files
     * @param bool  $monthView       If the query should be in month view (dayIds are monthIds)
     * @param array $queryTransforms The query transformations to apply
     *
     * @return array An array of day responses
     */
    public function getDay(
        array $dayIds,
        bool $recursive,
        bool $archive,
        bool $hidden,
        bool $monthView,
        array $queryTransforms = []
    ): array {
        // Check if we have any dayIds
        if (empty($dayIds)) {
            return [];
        }

        // Make new query
        $query = $this->connection->getQueryBuilder();

        // Get all entries also present in filecache
        $fileid = $query->createFunction('DISTINCT m.fileid');

        // We don't actually use m.datetaken here, but postgres
        // needs that all fields in ORDER BY are also in SELECT
        // when using DISTINCT on selected fields
        $query->select($fileid, ...TimelineQuery::TIMELINE_SELECT)
            ->from('memories', 'm')
        ;

        // Add hidden field
        if ($hidden) {
            $query->addSelect('cte_f.hidden');
        }

        // JOIN with mimetypes to get the mimetype
        $query->join('f', 'mimetypes', 'mimetypes', $query->expr()->eq('f.mimetype', 'mimetypes.id'));

        if ($monthView) {
            // Convert monthIds to dayIds
            $query->andWhere($query->expr()->orX(...array_map(fn ($monthId) => $query->expr()->andX(
                $query->expr()->gte('m.dayid', $query->createNamedParameter($monthId, IQueryBuilder::PARAM_INT)),
                $query->expr()->lte('m.dayid', $query->createNamedParameter($this->monthEndDayId($monthId), IQueryBuilder::PARAM_INT))
            ), $dayIds)));
        } else {
            // Filter by list of dayIds
            $query->andWhere($query->expr()->in('m.dayid', $query->createNamedParameter($dayIds, IQueryBuilder::PARAM_INT_ARRAY)));
        }

        // Add favorite field
        $this->addFavoriteTag($query);

        // Group and sort by date taken
        $query->orderBy('m.datetaken', 'DESC');
        $query->addOrderBy('m.fileid', 'DESC'); // tie-breaker

        // Apply all transformations
        $this->applyAllTransforms($queryTransforms, $query, false);

        // JOIN with filecache for existing files
        $query = $this->joinFilecache($query, null, $recursive, $archive, $hidden);

        // FETCH all photos in this day
        $day = $this->executeQueryWithCTEs($query)->fetchAll();

        // Post process the day in-place
        foreach ($day as &$photo) {
            $this->processDayPhoto($photo, $monthView);
        }

        return $day;
    }

    public function executeQueryWithCTEs(IQueryBuilder $query, string $psql = ''): \OCP\DB\IResult
    {
        $sql = empty($psql) ? $query->getSQL() : $psql;
        $params = $query->getParameters();
        $types = $query->getParameterTypes();

        // Get SQL
        $CTE_SQL = \array_key_exists('cteFoldersArchive', $params)
            ? self::CTE_FOLDERS_ARCHIVE()
            : self::CTE_FOLDERS(\array_key_exists('cteIncludeHidden', $params));

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
     * @param bool          $hidden    Whether to include hidden files
     */
    public function joinFilecache(
        IQueryBuilder $query,
        ?TimelineRoot $root = null,
        bool $recursive = true,
        bool $archive = false,
        bool $hidden = false
    ): IQueryBuilder {
        // Get the timeline root object
        if (null === $root) {
            // Cache the root object. This is fast when there are
            // multiple queries such as days-day preloading BUT that
            // means that any subsequent requests that don't match the
            // same root MUST specify a separate root this function
            if (null === $this->_root) {
                $this->_root = new TimelineRoot();

                // Populate the root using parameters from the request
                $fs = \OC::$server->get(FsManager::class);
                $fs->populateRoot($this->_root, $recursive);
            }

            // Use the cached / newly populated root
            $root = $this->_root;
        }

        // Join with memories
        $baseOp = $query->expr()->eq('f.fileid', 'm.fileid');
        if ($root->isEmpty()) {
            // This is illegal in most cases except albums,
            // which don't have a folder associated.
            if (!$this->_rootEmptyAllowed) {
                throw new \Exception('No valid root folder found (.nomedia?)');
            }

            return $query->innerJoin('m', 'filecache', 'f', $baseOp);
        }

        // Filter by folder (recursive or otherwise)
        $pathOp = null;
        if ($recursive) {
            // Join with folders CTE
            $this->addSubfolderJoinParams($query, $root, $archive, $hidden);
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
    private function processDays($days): array
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
     * @param array $row       The day response
     * @param bool  $monthView Whether the response is in month view
     */
    private function processDayPhoto(array &$row, bool $monthView = false): void
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

        // Get hidden field if present
        if (\array_key_exists('hidden', $row) && $row['hidden']) {
            $row['ishidden'] = 1;
        }
        unset($row['hidden']);

        // All cluster transformations
        ClustersBackend\Manager::applyDayPostTransforms($this->request, $row);

        // This field is only required due to the GROUP BY clause
        unset($row['datetaken']);

        // Calculate the AUID if we can
        if (\array_key_exists('epoch', $row) && \array_key_exists('size', $row)
           && ($epoch = (int) $row['epoch']) && ($size = (int) $row['size'])) {
            // compute AUID and discard size
            // epoch is used for ordering, so we keep it
            $row['auid'] = Exif::getAUID($epoch, $size);
            unset($row['size']);
        }

        // Convert dayId to monthId if needed
        if ($monthView) {
            $row['dayid'] = $this->dayIdToMonthId($row['dayid']);
        }
    }

    /**
     * Get all folders inside a top folder.
     */
    private function addSubfolderJoinParams(
        IQueryBuilder &$query,
        TimelineRoot &$root,
        bool $archive,
        bool $hidden
    ): void {
        // Add query parameters
        $query->setParameter('topFolderIds', $root->getIds(), IQueryBuilder::PARAM_INT_ARRAY);

        if ($archive) {
            $query->setParameter('cteFoldersArchive', true, IQueryBuilder::PARAM_BOOL);
        }

        if ($hidden) {
            $query->setParameter('cteIncludeHidden', true, IQueryBuilder::PARAM_BOOL);
        }
    }

    private function monthEndDayId(int $monthId): int
    {
        return (int) (strtotime(date('Ymt', $monthId * 86400)) / 86400);
    }

    private function dayIdToMonthId(int $dayId): int
    {
        static $memoize = [];
        if (\array_key_exists($dayId, $memoize)) {
            return $memoize[$dayId];
        }

        return $memoize[$dayId] = strtotime(date('Ym', $dayId * 86400).'01') / 86400;
    }
}
