<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\ClustersBackend;
use OCA\Memories\Exif;
use OCA\Memories\Settings\SystemConfig;
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
     * @param bool  $monthView       Whether the response should be in month view
     * @param bool  $reverse         Whether the response should be in reverse order
     * @param array $queryTransforms An array of query transforms to apply to the query
     *
     * @return array The days response
     */
    public function getDays(
        bool $recursive,
        bool $archive,
        bool $monthView,
        bool $reverse,
        array $queryTransforms = [],
    ): array {
        $query = $this->connection->getQueryBuilder();

        // Get all entries also present in filecache
        $query->select('m.dayid')
            ->selectAlias($query->func()->count(SQL::distinct($query, 'm.fileid')), 'count')
            ->from('memories', 'm')
        ;

        // Group and sort by dayid
        $query->addGroupBy('m.dayid')
            ->addOrderBy('m.dayid', 'DESC')
        ;

        // Apply all transformations
        $this->applyAllTransforms($queryTransforms, $query, true);

        // FILTER with filecache for timeline path
        $query = $this->filterFilecache($query, null, $recursive, $archive);

        // FETCH all days
        $rows = $this->executeQueryWithCTEs($query)->fetchAll();

        // Post process the days
        $rows = $this->postProcessDays($rows, $monthView);

        // Reverse order if needed
        if ($reverse) {
            $rows = array_reverse($rows);
        }

        return $rows;
    }

    /**
     * Get the day response from the database for the timeline.
     *
     * @param int[] $dayIds          The day ids to fetch
     * @param bool  $recursive       If the query should be recursive
     * @param bool  $archive         If the query should include only the archive folder
     * @param bool  $hidden          If the query should include hidden files
     * @param bool  $monthView       If the query should be in month view (dayIds are monthIds)
     * @param bool  $reverse         If the query should be in reverse order
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
        bool $reverse,
        array $queryTransforms = [],
    ): array {
        // Check if we have any dayIds
        if (empty($dayIds)) {
            return [];
        }

        // Make new query
        $query = $this->connection->getQueryBuilder();

        // We don't actually use m.datetaken here, but postgres
        // needs that all fields in ORDER BY are also in SELECT
        // when using DISTINCT on selected fields
        $query->select(SQL::distinct($query, 'm.fileid'), ...TimelineQuery::TIMELINE_SELECT)
            ->from('memories', 'm')
        ;

        // Add hidden field
        if ($hidden) {
            // we join with filecache anyway in this case, so just use the parent there
            // this means this will work directly in trigger compatibility mode
            $hSq = $this->connection->getQueryBuilder();
            $hSq->select($hSq->expr()->literal(1))
                ->from('cte_folders', 'cte_f')
                ->andWhere($hSq->expr()->eq('cte_f.fileid', 'f.parent'))
                ->andWhere($hSq->expr()->eq('cte_f.hidden', $hSq->expr()->literal(1)))
            ;
            $query->selectAlias(SQL::subquery($query, $hSq), 'hidden');
        }

        // JOIN with mimetypes to get the mimetype
        $query->join('f', 'mimetypes', 'mimetypes', $query->expr()->eq('f.mimetype', 'mimetypes.id'));

        if ($monthView) {
            // Convert monthIds to dayIds
            $query->andWhere($query->expr()->orX(...array_map(fn ($monthId) => $query->expr()->andX(
                $query->expr()->gte('m.dayid', $query->createNamedParameter($monthId, IQueryBuilder::PARAM_INT)),
                $query->expr()->lte('m.dayid', $query->createNamedParameter($this->dayIdMonthEnd($monthId), IQueryBuilder::PARAM_INT)),
            ), $dayIds)));
        } else {
            // Filter by list of dayIds
            $query->andWhere($query->expr()->in('m.dayid', $query->createNamedParameter($dayIds, IQueryBuilder::PARAM_INT_ARRAY)));
        }

        // Add favorite field
        $this->addFavoriteTag($query);

        // Group and sort by date taken
        $query->addOrderBy('m.datetaken', 'DESC');
        $query->addOrderBy('basename', 'DESC'); // https://github.com/pulsejet/memories/issues/985
        $query->addOrderBy('m.fileid', 'DESC'); // unique tie-breaker

        // Apply all transformations
        $this->applyAllTransforms($queryTransforms, $query, false);

        // JOIN with filecache to get the basename etc
        $query->innerJoin('m', 'filecache', 'f', $query->expr()->eq('m.fileid', 'f.fileid'));

        // Filter for files in the timeline path
        $query = $this->filterFilecache($query, null, $recursive, $archive, $hidden);

        // FETCH all photos in this day
        $day = $this->executeQueryWithCTEs($query)->fetchAll();

        // Post process the day in-place
        foreach ($day as &$photo) {
            $this->postProcessDayPhoto($photo, $monthView);
        }

        // Reverse order if needed
        if ($reverse) {
            $day = array_reverse($day);
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
            ? $this->CTE_FOLDERS_ARCHIVE()
            : $this->CTE_FOLDERS(\array_key_exists('cteIncludeHidden', $params));

        // Add WITH clause if needed
        if (str_contains($sql, 'cte_folders')) {
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
    public function filterFilecache(
        IQueryBuilder $query,
        ?TimelineRoot $root = null,
        bool $recursive = true,
        bool $archive = false,
        bool $hidden = false,
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

        if ($root->isEmpty()) {
            // This is illegal in most cases except albums,
            // which don't have a folder associated.
            if (!$this->_rootEmptyAllowed) {
                throw new \Exception('No valid root folder found (.nomedia?)');
            }

            // Nothing to do here
            return $query;
        }

        // Which field is the parent field for the record
        $parent = 'm.parent';

        // Check if triggers are properly set up
        if (!SystemConfig::get('memories.db.triggers.fcu')) {
            // Compatibility mode - JOIN filecache and use the parent from there (this is slow)
            $query->innerJoin('m', 'filecache', 'ff_f', $query->expr()->eq('m.fileid', 'ff_f.fileid'));
            $parent = 'ff_f.parent';
        }

        // Filter by folder (recursive or otherwise)
        if ($recursive) {
            // This are used later by the execution function
            $this->addSubfolderJoinParams($query, $root, $archive, $hidden);

            // Subquery to test parent folder
            $sq = $query->getConnection()->getQueryBuilder();
            $sq->select($sq->expr()->literal(1))
                ->from('cte_folders', 'cte_f')
                ->where($sq->expr()->eq($parent, 'cte_f.fileid'))
            ;

            // Filter files in one of the timeline folders
            $query->andWhere(SQL::exists($query, $sq));
        } else {
            // If getting non-recursively folder only check for parent
            $query->andWhere($query->expr()->eq($parent, $query->createNamedParameter($root->getOneId(), IQueryBuilder::PARAM_INT)));
        }

        return $query;
    }

    /**
     * Process the days response.
     *
     * @param array $rows      the days response
     * @param bool  $monthView Whether the response is in month view
     */
    private function postProcessDays(array $rows, bool $monthView): array
    {
        foreach ($rows as &$row) {
            $row['dayid'] = (int) $row['dayid'];
            $row['count'] = (int) $row['count'];
        }

        // Convert to months if needed
        if ($monthView) {
            return array_values(array_reduce($rows, function ($carry, $item) {
                $monthId = $this->dayIdToMonthId($item['dayid']);

                if (!\array_key_exists($monthId, $carry)) {
                    $carry[$monthId] = ['dayid' => $monthId, 'count' => 0];
                }

                $carry[$monthId]['count'] += $item['count'];

                return $carry;
            }, []));
        }

        return $rows;
    }

    /**
     * Process the single day response.
     *
     * @param array $row       A photo in the day response
     * @param bool  $monthView Whether the response is in month view
     */
    private function postProcessDayPhoto(array &$row, bool $monthView = false): void
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
        if ($row['categoryid'] ?? null) {
            $row['isfavorite'] = 1;
        }
        unset($row['categoryid']);

        // Get hidden field if present
        if ($row['hidden'] ?? null) {
            $row['ishidden'] = 1;
        }
        unset($row['hidden']);

        // All cluster transformations
        ClustersBackend\Manager::applyDayPostTransforms($this->request, $row);

        // This field is only required due to the GROUP BY clause
        unset($row['datetaken']);

        // Calculate the AUID if we can
        if (($epoch = $row['epoch'] ?? null) && ($size = $row['size'] ?? null)) {
            // compute AUID and discard size
            // epoch is used for ordering, so we keep it
            $row['auid'] = Exif::getAUID((int) $epoch, (int) $size);
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
        bool $hidden,
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

    private function dayIdMonthEnd(int $monthId): int
    {
        return (int) (strtotime(date('Ymt', $monthId * 86400)) / 86400);
    }

    private function dayIdToMonthId(int $dayId): int
    {
        static $memoize = [];
        if ($cache = $memoize[$dayId] ?? null) {
            return $cache;
        }

        return $memoize[$dayId] = strtotime(date('Ym', $dayId * 86400).'01') / 86400;
    }
}
