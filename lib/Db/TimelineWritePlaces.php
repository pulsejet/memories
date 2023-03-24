<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

trait TimelineWritePlaces
{
    protected IDBConnection $connection;

    /**
     * Add places data for a file.
     *
     * @param int        $fileId The file ID
     * @param null|float $lat    The latitude of the file
     * @param null|float $lon    The longitude of the file
     */
    protected function updatePlacesData(int $fileId, $lat, $lon): void
    {
        // Get GIS type
        $gisType = \OCA\Memories\Util::placesGISType();

        // Check if valid
        if ($gisType <= 0) {
            return;
        }

        // Delete previous records
        $query = $this->connection->getQueryBuilder();
        $query->delete('memories_places')
            ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
        ;
        $query->executeStatement();

        // Just remove from if the point is no longer valid
        if (null === $lat || null === $lon) {
            return;
        }

        // Construct WHERE clause depending on GIS type
        $where = null;
        if (1 === $gisType) {
            $where = "ST_Contains(geometry, ST_GeomFromText('POINT({$lon} {$lat})'))";
        } elseif (2 === $gisType) {
            $where = "POINT('{$lon},{$lat}') <@ geometry";
        } else {
            return;
        }

        // Make query to memories_planet table
        $query = $this->connection->getQueryBuilder();
        $query->select($query->createFunction('DISTINCT(osm_id)'))
            ->from('memories_planet_geometry')
            ->where($query->createFunction($where))
        ;

        // Cancel out inner rings
        $query->groupBy('poly_id', 'osm_id');
        $query->having($query->createFunction('SUM(type_id) > 0'));

        // memories_planet_geometry has no *PREFIX*
        $sql = str_replace('*PREFIX*memories_planet_geometry', 'memories_planet_geometry', $query->getSQL());

        // Run query
        $rows = $this->connection->executeQuery($sql)->fetchAll();

        // Insert records in transaction
        $this->connection->beginTransaction();

        foreach ($rows as $row) {
            $query = $this->connection->getQueryBuilder();
            $query->insert('memories_places')
                ->values([
                    'fileid' => $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                    'osm_id' => $query->createNamedParameter($row['osm_id'], IQueryBuilder::PARAM_INT),
                ])
            ;
            $query->executeStatement();
        }

        $this->connection->commit();
    }
}
