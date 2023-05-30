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

use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Util;
use OCP\IRequest;

class PlacesBackend extends Backend
{
    protected TimelineQuery $tq;
    protected IRequest $request;

    public function __construct(TimelineQuery $tq, IRequest $request)
    {
        $this->tq = $tq;
        $this->request = $request;
    }

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
        return Util::placesGISType() > 0;
    }

    public function transformDayQuery(&$query, bool $aggregate): void
    {
        $locationId = (int) $this->request->getParam('places');

        $query->innerJoin('m', 'memories_places', 'mp', $query->expr()->andX(
            $query->expr()->eq('mp.fileid', 'm.fileid'),
            $query->expr()->eq('mp.osm_id', $query->createNamedParameter($locationId)),
        ));
    }

    public function getClusters(): array
    {
        $inside = (int) $this->request->getParam('inside', 0);
        $marked = (int) $this->request->getParam('mark', 1);

        $query = $this->tq->getBuilder();

        // SELECT location name and count of photos
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'), 'count');
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
            $mpJoinOn[] = $query->createFunction("EXISTS ({$sub->getSQL()})");

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
            $query->where($query->expr()->eq('e.admin_level', $query->expr()->literal(2, \PDO::PARAM_INT)));
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
        $query = $this->tq->joinFilecache($query);

        // GROUP and ORDER by tag name
        $query->groupBy('e.osm_id');

        // We use this as the subquery for the main query, where we also re-join with
        // oc_memories_planet to the the names from the IDS
        // If we just AGGREGATE+GROUP with the name in one query, then it can't use indexes
        $sub = $query;

        // Create new query and copy over parameters (and types)
        $query = $this->tq->getBuilder();
        $query->setParameters($sub->getParameters(), $sub->getParameterTypes());

        // Create the subquery function for selecting from it
        $sqf = $query->createFunction("({$sub->getSQL()})");

        // SELECT osm_id
        $query->select('sub.osm_id', 'sub.count', 'e.name', 'e.other_names')->from($sqf, 'sub');

        // INNER JOIN back on the planet table to get the names
        $query->innerJoin('sub', 'memories_planet', 'e', $query->expr()->eq('e.osm_id', 'sub.osm_id'));

        // WHERE at least 3 photos if want marked clusters
        if ($marked) {
            $query->andWhere($query->expr()->gte('sub.count', $query->expr()->literal(3, \PDO::PARAM_INT)));
        }

        // ORDER BY name and osm_id
        $query->orderBy($query->createFunction('sub.count'), 'DESC');
        $query->addOrderBy('e.name');
        $query->addOrderBy('e.osm_id'); // tie-breaker

        // FETCH all tags
        $places = $this->tq->executeQueryWithCTEs($query)->fetchAll();

        // Post process
        $lang = Util::getUserLang();
        foreach ($places as &$row) {
            $row['osm_id'] = (int) $row['osm_id'];
            $row['count'] = $marked ? 0 : (int) $row['count']; // the count is incorrect
            self::choosePlaceLang($row, $lang);
        }

        return $places;
    }

    public static function getClusterId(array $cluster)
    {
        return $cluster['osm_id'];
    }

    public function getPhotos(string $name, ?int $limit = null): array
    {
        $query = $this->tq->getBuilder();

        // SELECT all photos with this tag
        $query->select('f.fileid', 'f.etag')->from('memories_places', 'mp')
            ->where($query->expr()->eq('mp.osm_id', $query->createNamedParameter((int) $name)))
        ;

        // WHERE these items are memories indexed photos
        $query->innerJoin('mp', 'memories', 'm', $query->expr()->eq('m.fileid', 'mp.fileid'));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->joinFilecache($query);

        // MAX number of photos
        if (null !== $limit) {
            $query->setMaxResults($limit);
        }

        // FETCH tag photos
        return $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];
    }

    /**
     * Choose the best name for the place.
     */
    public static function choosePlaceLang(array &$place, string $lang): array
    {
        try {
            $otherNames = json_decode($place['other_names'], true);
            if (isset($otherNames[$lang])) {
                $place['name'] = $otherNames[$lang];
            }
        } catch (\Error $e) {
            // Ignore
        } finally {
            unset($place['other_names']);
        }

        return $place;
    }
}
