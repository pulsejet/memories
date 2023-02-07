<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

trait TimelineQueryPlaces
{
    protected IDBConnection $connection;

    public function transformPlaceFilter(IQueryBuilder &$query, string $userId, int $locationId)
    {
        $query->innerJoin('m', 'memories_places', 'mp', $query->expr()->andX(
            $query->expr()->eq('mp.fileid', 'm.fileid'),
            $query->expr()->eq('mp.osm_id', $query->createNamedParameter($locationId)),
        ));
    }

    public function getPlaces(TimelineRoot &$root)
    {
        $query = $this->connection->getQueryBuilder();

        // SELECT location name and count of photos
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'), 'count');
        $query->select('e.osm_id', 'e.name', $count)->from('memories_planet', 'e');

        // WHERE there are items with this osm_id
        $query->innerJoin('e', 'memories_places', 'mp', $query->expr()->eq('mp.osm_id', 'e.osm_id'));

        // WHERE these items are memories indexed photos
        $query->innerJoin('mp', 'memories', 'm', $query->expr()->eq('m.fileid', 'mp.fileid'));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->joinFilecache($query, $root, true, false);

        // GROUP and ORDER by tag name
        $query->groupBy('e.osm_id', 'e.name');
        $query->orderBy($query->createFunction('LOWER(e.name)'), 'ASC');
        $query->addOrderBy('e.osm_id'); // tie-breaker

        // FETCH all tags
        $cursor = $this->executeQueryWithCTEs($query);
        $places = $cursor->fetchAll();

        // Post process
        foreach ($places as &$row) {
            $row['osm_id'] = (int) $row['osm_id'];
            $row['count'] = (int) $row['count'];
        }

        return $places;
    }

    public function getPlacePreviews(int $id, TimelineRoot &$root)
    {
        $query = $this->connection->getQueryBuilder();

        // SELECT all photos with this tag
        $query->select('f.fileid', 'f.etag')->from('memories_places', 'mp')
            ->where($query->expr()->eq('mp.osm_id', $query->createNamedParameter($id)))
        ;

        // WHERE these items are memories indexed photos
        $query->innerJoin('mp', 'memories', 'm', $query->expr()->eq('m.fileid', 'mp.fileid'));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->joinFilecache($query, $root, true, false);

        // MAX 8
        $query->setMaxResults(8);

        // FETCH tag previews
        $cursor = $this->executeQueryWithCTEs($query);
        $ans = $cursor->fetchAll();

        // Post-process
        foreach ($ans as &$row) {
            $row['fileid'] = (int) $row['fileid'];
        }

        return $ans;
    }
}
