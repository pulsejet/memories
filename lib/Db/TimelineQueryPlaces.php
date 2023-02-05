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
        $query->innerJoin('m', 'memories_geo', 'mg', $query->expr()->andX(
            $query->expr()->eq('mg.fileid', 'm.fileid'),
            $query->expr()->eq('mg.osm_id', $query->createNamedParameter($locationId)),
        ));
    }

    public function getPlaces(TimelineRoot &$root)
    {
        $query = $this->connection->getQueryBuilder();

        // SELECT location name and count of photos
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'), 'count');
        $query->select('p.osm_id', 'p.name', $count)->from('memories_planet', 'p');

        // WHERE there are items with this osm_id
        $query->innerJoin('p', 'memories_geo', 'mg', $query->expr()->eq('mg.osm_id', 'p.osm_id'));

        // WHERE these items are memories indexed photos
        $query->innerJoin('mg', 'memories', 'm', $query->expr()->eq('m.fileid', 'mg.fileid'));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->joinFilecache($query, $root, true, false);

        // GROUP and ORDER by tag name
        $query->groupBy('p.osm_id');
        $query->orderBy($query->createFunction('LOWER(p.name)'), 'ASC');
        $query->addOrderBy('p.osm_id'); // tie-breaker

        // FETCH all tags
        $sql = str_replace('*PREFIX*memories_planet', 'memories_planet', $query->getSQL());
        $cursor = $this->executeQueryWithCTEs($query, $sql);
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
        $query->select('f.fileid', 'f.etag')->from('memories_geo', 'mg')
            ->where($query->expr()->eq('mg.osm_id', $query->createNamedParameter($id)))
        ;

        // WHERE these items are memories indexed photos
        $query->innerJoin('mg', 'memories', 'm', $query->expr()->eq('m.fileid', 'mg.fileid'));

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
