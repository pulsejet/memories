<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

const LAT_KEY = 'GPSLatitude';
const LON_KEY = 'GPSLongitude';

trait TimelineWritePlaces
{
    protected IDBConnection $connection;
    protected LoggerInterface $logger;

    /**
     * Add places data for a file.
     *
     * @param int    $fileId The file ID
     * @param ?float $lat    The latitude of the file
     * @param ?float $lon    The longitude of the file
     *
     * @return int[] The list of osm_id of the places
     */
    public function updatePlacesData(int $fileId, ?float $lat, ?float $lon): array
    {
        // Get GIS type
        $gisType = SystemConfig::gisType();

        // Check if valid
        if ($gisType <= 0) {
            return [];
        }

        // Delete previous records
        Util::transaction(function () use ($fileId): void {
            $query = $this->connection->getQueryBuilder();
            $query->delete('memories_places')
                ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
                ->executeStatement()
            ;
        });

        // Just remove from if the point is no longer valid
        if (null === $lat || null === $lon) {
            return [];
        }

        // Get places
        try {
            $places = \OC::$server->get(\OCA\Memories\Service\Places::class);
            $rows = Util::transaction(static fn () => $places->queryPoint($lat, $lon));
        } catch (\Exception $e) {
            $this->logger->error("Error querying places: {$e->getMessage()}", ['app' => 'memories']);

            return [];
        }

        // Get last ID, i.e. the ID with highest admin_level but <= 8
        $crows = array_filter($rows, static fn ($row) => $row['admin_level'] <= 8);
        $markRow = array_pop($crows);

        // Insert records in transaction
        Util::transaction(function () use ($fileId, $rows, $markRow): void {
            foreach ($rows as $row) {
                $isMark = $markRow && $row['osm_id'] === $markRow['osm_id'];

                // Insert the place
                $query = $this->connection->getQueryBuilder();
                $query->insert('memories_places')
                    ->values([
                        'fileid' => $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                        'osm_id' => $query->createNamedParameter($row['osm_id'], IQueryBuilder::PARAM_INT),
                        'mark' => $query->createNamedParameter($isMark, IQueryBuilder::PARAM_BOOL),
                    ])
                    ->executeStatement()
                ;
            }
        });

        // Return list of osm_id
        return array_map(static fn ($row) => (int) $row['osm_id'], $rows);
    }

    /**
     * Process the location part of exif data.
     *
     * Also update the exif data with the tzid from location (LocationTZID)
     * Performs an in-place update of the exif data.
     *
     * @param int    $fileId  The file ID
     * @param array  $exif    The exif data (will change)
     * @param ?array $prevRow The previous row of data
     *
     * @return array Update values
     */
    protected function processExifLocation(int $fileId, array &$exif, ?array $prevRow): array
    {
        // Store location data
        [$lat, $lon] = self::readCoord($exif);
        $oldLat = $prevRow ? (float) $prevRow['lat'] : null;
        $oldLon = $prevRow ? (float) $prevRow['lon'] : null;
        $mapCluster = $prevRow ? (int) $prevRow['mapcluster'] : -1;
        $osmIds = [];

        if ($lat || $lon || $oldLat || $oldLon) {
            try {
                $mapCluster = $this->mapGetCluster($mapCluster, $lat, $lon, $oldLat, $oldLon);
            } catch (\Exception $e) {
                $logger = \OC::$server->get(LoggerInterface::class);
                $logger->log(3, 'Error updating map cluster data: '.$e->getMessage(), ['app' => 'memories']);
            }

            try {
                $osmIds = $this->updatePlacesData($fileId, $lat, $lon);
            } catch (\Exception $e) {
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
            ->andWhere($query->expr()->eq('admin_level', $query->expr()->literal(-7, IQueryBuilder::PARAM_INT)))
        ;

        // Get name of timezone
        $tzName = $query->executeQuery()->fetchOne();
        if ($tzName) {
            $exif['LocationTZID'] = $tzName;
        }
    }

    /**
     * Read coordinates from array and round to 6 decimal places.
     *
     * Modifies the EXIF array to remove invalid coordinates.
     *
     * @return (null|float)[]
     *
     * @psalm-return list{float|null, float|null}
     */
    private static function readCoord(array &$exif): array
    {
        $lat = \array_key_exists(LAT_KEY, $exif) ? round((float) $exif[LAT_KEY], 6) : null;
        $lon = \array_key_exists(LON_KEY, $exif) ? round((float) $exif[LON_KEY], 6) : null;

        // Make sure we have valid coordinates
        if (null === $lat || null === $lon
        || abs($lat) > 90 || abs($lon) > 180
        || (abs($lat) < 0.00001 && abs($lon) < 0.00001)) {
            $lat = $lon = null;
        }

        // Remove invalid coordinates
        if (null === $lat && \array_key_exists(LAT_KEY, $exif)) {
            unset($exif[LAT_KEY]);
        }
        if (null === $lon && \array_key_exists(LON_KEY, $exif)) {
            unset($exif[LON_KEY]);
        }

        return [$lat, $lon];
    }
}
