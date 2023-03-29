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
        $query = $this->tq->getBuilder();

        // SELECT location name and count of photos
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'), 'count');
        $query->select('e.osm_id', $count)->from('memories_planet', 'e');

        // WHERE these are not special clusters (e.g. timezone)
        $query->where($query->expr()->gt('e.admin_level', $query->createNamedParameter(0, \PDO::PARAM_INT)));

        // WHERE there are items with this osm_id
        $query->innerJoin('e', 'memories_places', 'mp', $query->expr()->eq('mp.osm_id', 'e.osm_id'));

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

        // ORDER BY name and osm_id
        $query->orderBy($query->createFunction('LOWER(e.name)'), 'ASC');
        $query->addOrderBy('e.osm_id'); // tie-breaker

        // FETCH all tags
        $places = $this->tq->executeQueryWithCTEs($query)->fetchAll();

        // Post process
        $lang = Util::getUserLang();
        foreach ($places as &$row) {
            $row['osm_id'] = (int) $row['osm_id'];
            $row['count'] = (int) $row['count'];
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
