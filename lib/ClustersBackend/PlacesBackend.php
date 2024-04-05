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
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IRequest;

class PlacesBackend extends Backend
{
    public function __construct(
        protected TimelineQuery $tq,
        protected IRequest $request,
    ) {}

    public static function appName(): string
    {
        return 'Places';
    }

    public static function clusterType(): string
    {
        return 'places';
    }

    public function isEnabled(): bool
    {
        return SystemConfig::gisType() > 0;
    }

    public function transformDayQuery(IQueryBuilder &$query, bool $aggregate): void
    {
        $locationId = (int) $this->request->getParam('places');

        $query->innerJoin('m', 'memories_places', 'mp', $query->expr()->andX(
            $query->expr()->eq('mp.fileid', 'm.fileid'),
            $query->expr()->eq('mp.osm_id', $query->createNamedParameter($locationId)),
        ));
    }

    public function getClustersInternal(int $fileid = 0): array
    {
        if ($fileid) {
            throw new \Exception('PlacesBackend: fileid filter not implemented');
        }

        $inside = (int) $this->request->getParam('inside', 0);
        $marked = (int) $this->request->getParam('mark', 1);
        $covers = (bool) $this->request->getParam('covers', 1);

        $query = $this->tq->getBuilder();

        // SELECT location name and count of photos
        $count = $query->func()->count(SQL::distinct($query, 'm.fileid'), 'count');
        $query->select('e.osm_id', $count)->from('memories_planet', 'e');

        // WHERE these are not special clusters (e.g. timezone)
        $query->where($query->expr()->gt('e.admin_level', $query->expr()->literal(0, \PDO::PARAM_INT)));

        // WHERE there are items with this osm_id
        $mpJoinOn = [$query->expr()->eq('mp.osm_id', 'e.osm_id')];

        // AND these items are inside the requested place
        if ($inside > 0) {
            $sub = $this->tq->getBuilder();
            $sub->select($query->expr()->literal(1))->from('memories_places', 'mp_sq')
                ->where($sub->expr()->eq('mp_sq.osm_id', $query->createNamedParameter($inside, \PDO::PARAM_INT)))
                ->andWhere($sub->expr()->eq('mp_sq.fileid', 'mp.fileid'))
            ;
            $mpJoinOn[] = SQL::exists($query, $sub);

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

        // AND these items are marked (only if not inside)
        elseif ($marked > 0) {
            $mpJoinOn[] = $query->expr()->eq('mp.mark', $query->expr()->literal(1, \PDO::PARAM_INT));
        }

        // JOIN on memories_places
        $query->innerJoin('e', 'memories_places', 'mp', $query->expr()->andX(...$mpJoinOn));

        // WHERE these items are memories indexed photos
        $query->innerJoin('mp', 'memories', 'm', $query->expr()->eq('m.fileid', 'mp.fileid'));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->filterFilecache($query);

        // GROUP and ORDER by tag name
        $query->groupBy('e.osm_id');

        // We use this as the subquery for the main query, where we also re-join with
        // oc_memories_planet to the the names from the IDS
        // If we just AGGREGATE+GROUP with the name in one query, then it can't use indexes
        $query = SQL::materialize($query, 'sub');

        // INNER JOIN back on the planet table to get the names
        $query->innerJoin('sub', 'memories_planet', 'e', $query->expr()->eq('e.osm_id', 'sub.osm_id'));
        $query->addSelect('e.name', 'e.other_names');

        // WHERE at least 3 photos if want marked clusters
        if ($marked) {
            $query->andWhere($query->expr()->gte('sub.count', $query->expr()->literal(3, \PDO::PARAM_INT)));
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
            Covers::selectCover(
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
        $places = $this->tq->executeQueryWithCTEs($query)->fetchAll();

        // Post process
        $lang = Util::getUserLang();
        foreach ($places as &$row) {
            $row['osm_id'] = (int) $row['osm_id'];
            $row['count'] = (int) $row['count'];

            $row['name'] = self::translateName($lang, $row['name'], $row['other_names']);
            unset($row['other_names']);
        }

        return $places;
    }

    public static function getClusterId(array $cluster): int|string
    {
        return $cluster['osm_id'];
    }

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
            Covers::filterCover($query, self::clusterType(), 'mp', 'fileid', 'osm_id');
        } elseif (null !== $limit) {
            $query->setMaxResults($limit);
        }

        // Filter by fileid if specified
        if (null !== $fileid) {
            $query->andWhere($query->expr()->eq('m.fileid', $query->createNamedParameter($fileid, \PDO::PARAM_INT)));
        }

        // FETCH tag photos
        return $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];
    }

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
}
