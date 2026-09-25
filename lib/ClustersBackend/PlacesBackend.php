<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Varun Patil <radialapps@gmail.com>
 * @author Varun Patil <radialapps@gmail.com>
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\ClustersBackend;

use OCA\Memories\Db\SQL;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Settings\SystemConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IRequest;

final class PlacesBackend extends Backend
{
    public const CLUSTER_TYPE = 'places';

    public function __construct(
        protected TimelineQuery $tq,
        protected IRequest $request,
        protected SystemConfig $systemConfig,
        protected Covers $covers,
    ) {}

    #[\Override]
    public static function appName(): string
    {
        return 'Places';
    }

    #[\Override]
    public static function clusterType(): string
    {
        return self::CLUSTER_TYPE;
    }

    #[\Override]
    public function isEnabled(): bool
    {
        return $this->systemConfig->gisType() > 0;
    }

    #[\Override]
    public function transformDayQuery(IQueryBuilder &$query, bool $aggregate): void
    {
        $locId = $this->request->getParam('places');

        // Files that have no GPS coordinates set
        if ('NULL' === $locId) {
            $query->andWhere($query->expr()->orX(
                $query->expr()->isNull('m.lat'),
                $query->expr()->isNull('m.lon'),
            ));

            return;
        }

        $query->innerJoin('m', 'memories_places', 'mp', $query->expr()->andX(
            $query->expr()->eq('mp.fileid', 'm.fileid'),
            $query->expr()->eq('mp.osm_id', $query->createNamedParameter((int) $locId)),
        ));
    }

    #[\Override]
    public function getClustersInternal(int $fileid = 0): array
    {
        if ($fileid) {
            throw new \Exception('PlacesBackend: fileid filter not implemented');
        }

        $inside = (int) $this->request->getParam('inside', 0);
        $marked = (int) $this->request->getParam('mark', 1);
        $covers = (bool) $this->request->getParam('covers', 1);

        $query = $this->tq->getBuilder();

        // SELECT osm_id and count of photos
        $count = $query->func()->count('m.fileid');
        $query->select('mp.osm_id')->from('memories_places', 'mp');
        $query->selectAlias($count, 'count');

        // AND these items are inside the requested place
        if ($inside > 0) {
            $sub = $this->tq->getBuilder();
            $sub->select($query->expr()->literal(1))->from('memories_places', 'mp_sq')
                ->where($sub->expr()->eq('mp_sq.osm_id', $query->createNamedParameter($inside, \PDO::PARAM_INT)))
                ->andWhere($sub->expr()->eq('mp_sq.fileid', 'mp.fileid'))
            ;
            $query->andWhere(SQL::exists($query, $sub));
        }

        // Else if we are looking for countries
        elseif (-1 === $inside) {
            // no mark filter
        }

        // AND these items are marked (only if not inside)
        elseif ($marked > 0) {
            $query->andWhere($query->expr()->eq('mp.mark', $query->expr()->literal(1, \PDO::PARAM_INT)));
        }

        // WHERE these items are memories indexed photos
        $query->innerJoin('mp', 'memories', 'm', $query->expr()->eq('m.fileid', 'mp.fileid'));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->filterFilecache($query);

        // GROUP BY osm_id
        $query->groupBy('mp.osm_id');

        // WHERE at least 3 photos if want marked clusters
        if ($marked) {
            $query->having($query->expr()->gte($count, SQL::literal($query, 3, \PDO::PARAM_INT)));
        }

        // Materialize the aggregation, then join planet once per place
        // to filter by admin_level and fetch the names from the IDs.
        // If we just AGGREGATE+GROUP with the name in one query, then it can't use indexes
        $query = SQL::materialize($query, 'sub');

        // INNER JOIN planet to get the names
        $query->innerJoin('sub', 'memories_planet', 'e', $query->expr()->eq('e.osm_id', 'sub.osm_id'));
        $query->addSelect('e.name', 'e.other_names');

        // WHERE these are not special clusters (e.g. timezone)
        $query->andWhere($query->expr()->gt('e.admin_level', $query->expr()->literal(0, \PDO::PARAM_INT)));

        // AND these places are inside the requested place
        if ($inside > 0) {
            // Add WHERE clauses to main query to filter out admin_levels
            $sub = $this->tq->getBuilder();
            $sub->select('e_sq.admin_level')
                ->from('memories_planet', 'e_sq')
                ->where($sub->expr()->eq('e_sq.osm_id', $query->createNamedParameter($inside, \PDO::PARAM_INT)))
            ;
            $adminSql = "({$sub->getSQL()})";
            $query->andWhere($query->expr()->gt('e.admin_level', $query->createFunction($adminSql)))
                ->andWhere($query->expr()->lte('e.admin_level', $query->createFunction("{$adminSql} + 3")))
            ;
        }

        // Else if we are looking for countries
        elseif (-1 === $inside) {
            $query->andWhere($query->expr()->eq('e.admin_level', $query->expr()->literal(2, \PDO::PARAM_INT)));
        }

        // ORDER BY name and osm_id
        $query->addOrderBy('sub.count', 'DESC');
        $query->addOrderBy('e.name');
        $query->addOrderBy('e.osm_id'); // tie-breaker

        // GROUP BY everything
        $query->addGroupBy('sub.osm_id', 'e.osm_id', 'sub.count', 'e.name', 'e.other_names');

        // SELECT to get all covers
        if ($covers) {
            $query = SQL::materialize($query, 'sub');
            $this->covers->selectCover(
                query: $query,
                type: self::clusterType(),
                clusterTable: 'sub',
                clusterTableId: 'osm_id',
                objectTable: 'memories_places',
                objectTableObjectId: 'fileid',
                objectTableClusterId: 'osm_id',
            );

            // SELECT etag for the cover
            $query = SQL::materialize($query, 'sub');
            $this->tq->selectEtag($query, 'sub.cover', 'cover_etag');
        }

        // FETCH all tags
        $places = $this->tq->executeQueryWithCTEs($query)->fetchAllAssociative();

        // Post process
        $lang = $this->systemConfig->getUserLang();
        foreach ($places as &$row) {
            $row['osm_id'] = (int) $row['osm_id'];
            $row['count'] = (int) $row['count'];

            $row['name'] = self::translateName($lang, $row['name'], $row['other_names']);
            unset($row['other_names']);
        }

        return $places;
    }

    #[\Override]
    public static function getClusterId(array $cluster): int|string
    {
        return $cluster['osm_id'];
    }

    #[\Override]
    public function getPhotos(string $name, ?int $limit = null, ?int $fileid = null): array
    {
        $query = $this->tq->getBuilder();

        // SELECT all photos with this tag
        $query->select('m.fileid', 'f.etag', 'mp.osm_id')
            ->from('memories_places', 'mp')
            ->where($query->expr()->eq('mp.osm_id', $query->createNamedParameter((int) $name)))
        ;

        // WHERE these items are memories indexed photos
        $query->innerJoin('mp', 'memories', 'm', $query->expr()->eq('m.fileid', 'mp.fileid'));

        // JOIN with the filecache table
        $query->innerJoin('m', 'filecache', 'f', $query->expr()->eq('m.fileid', 'f.fileid'));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->filterFilecache($query);

        // MAX number of photos
        if (-6 === $limit) {
            $this->covers->filterCover($query, self::clusterType(), 'mp', 'fileid', 'osm_id');
        } elseif (null !== $limit) {
            $query->setMaxResults($limit);
        }

        // Filter by fileid if specified
        if (null !== $fileid) {
            $query->andWhere($query->expr()->eq('m.fileid', $query->createNamedParameter($fileid, \PDO::PARAM_INT)));
        }

        // FETCH tag photos
        return $this->tq->executeQueryWithCTEs($query)->fetchAllAssociative();
    }

    #[\Override]
    public function getClusterIdFrom(array $photo): int
    {
        return (int) $photo['osm_id'];
    }

    /**
     * Choose the best name for the place.
     */
    public static function translateName(string $lang, string $name, ?string $otherNames): string
    {
        if (empty($otherNames)) {
            return $name;
        }

        try {
            // Decode the other names
            $json = json_decode($otherNames, true);

            // Check if the language is available
            if ($translated = ($json[$lang] ?? null)) {
                return (string) $translated;
            }
        } catch (\Error) {
            // Ignore errors, just use original name
        }

        return $name;
    }

    #[\Override]
    public function setCover(array $photo, bool $manual = false): void
    {
        $this->covers->setBackendCover($this, $photo, $manual);
    }
}
