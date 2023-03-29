<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

trait TimelineWritePlaces
{
    protected IDBConnection $connection;

    /**
     * Process the location part of exif data.
     *
     * Also update the exif data with the tzid from location (LocationTZID)
     * Performs an in-place update of the exif data.
     *
     * @param int        $fileId  The file ID
     * @param array      $exif    The exif data (will change)
     * @param array|bool $prevRow The previous row of data
     *
     * @return array Update values
     */
    protected function processExifLocation(int $fileId, array &$exif, $prevRow): array
    {
        // Store location data
        $lat = \array_key_exists('GPSLatitude', $exif) ? (float) $exif['GPSLatitude'] : null;
        $lon = \array_key_exists('GPSLongitude', $exif) ? (float) $exif['GPSLongitude'] : null;
        $oldLat = $prevRow ? (float) $prevRow['lat'] : null;
        $oldLon = $prevRow ? (float) $prevRow['lon'] : null;
        $mapCluster = $prevRow ? (int) $prevRow['mapcluster'] : -1;
        $osmIds = [];

        if ($lat || $lon || $oldLat || $oldLon) {
            try {
                $mapCluster = $this->mapGetCluster($mapCluster, $lat, $lon, $oldLat, $oldLon);
            } catch (\Error $e) {
                $logger = \OC::$server->get(LoggerInterface::class);
                $logger->log(3, 'Error updating map cluster data: '.$e->getMessage(), ['app' => 'memories']);
            }

            try {
                $osmIds = $this->updatePlacesData($fileId, $lat, $lon);
            } catch (\Error $e) {
                $logger = \OC::$server->get(LoggerInterface::class);
                $logger->log(3, 'Error updating places data: '.$e->getMessage(), ['app' => 'memories']);
            }
        }

        // NULL if invalid
        $mapCluster = $mapCluster <= 0 ? null : $mapCluster;

        // Set tzid from location if not present
        $this->setTzidFromLocation($exif, $osmIds);

        // Return update values
        return [$lat, $lon, $mapCluster, $osmIds];
    }

    /**
     * Add places data for a file.
     *
     * @param int        $fileId The file ID
     * @param null|float $lat    The latitude of the file
     * @param null|float $lon    The longitude of the file
     *
     * @return array The list of osm_id of the places
     */
    protected function updatePlacesData(int $fileId, $lat, $lon): array
    {
        // Get GIS type
        $gisType = \OCA\Memories\Util::placesGISType();

        // Check if valid
        if ($gisType <= 0) {
            return [];
        }

        // Delete previous records
        $query = $this->connection->getQueryBuilder();
        $query->delete('memories_places')
            ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
        ;
        $query->executeStatement();

        // Just remove from if the point is no longer valid
        if (null === $lat || null === $lon) {
            return [];
        }

        // Construct WHERE clause depending on GIS type
        $where = null;
        if (1 === $gisType) {
            $where = "ST_Contains(geometry, ST_GeomFromText('POINT({$lon} {$lat})'))";
        } elseif (2 === $gisType) {
            $where = "POINT('{$lon},{$lat}') <@ geometry";
        } else {
            return [];
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

        // Return list of osm_id
        return array_map(fn ($row) => $row['osm_id'], $rows);
    }

    /**
     * Set timezone offset from location if not present.
     *
     * @param array $exif   The exif data
     * @param array $osmIds The list of osm_id of the places
     */
    private function setTzidFromLocation(array &$exif, array $osmIds): void
    {
        // Make sure we have some places
        if (empty($osmIds)) {
            return;
        }

        // Get timezone offset from places
        $query = $this->connection->getQueryBuilder();
        $query->select('name')
            ->from('memories_planet')
            ->where($query->expr()->in('osm_id', $query->createNamedParameter($osmIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($query->expr()->eq('admin_level', $query->createNamedParameter(-7, IQueryBuilder::PARAM_INT)))
        ;

        // Get name of timezone
        $tzName = $query->executeQuery()->fetchOne();
        if ($tzName) {
            $exif['LocationTZID'] = $tzName;
        }
    }
}
