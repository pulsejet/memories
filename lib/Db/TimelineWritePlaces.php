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
     * @param array  $exif    The exif numeric data
     * @param ?array $prevRow The previous row of data
     *
     * @return array Update values
     */
    protected function processExifLocation(int $fileId, array &$exif, array &$exifNumeric, ?array $prevRow): array
    {
        // Store location data
        [$lat, $lon] = self::readCoord($exifNumeric);
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
        $this->setTzidFromLocation($exif, $exifNumeric, $osmIds, $lat, $lon);

        // Return update values
        return [$lat, $lon, $mapCluster, $osmIds];
    }

    /**
     * Set timezone offset from location if not present.
     *
     * @param array $exif   The exif data
     * @param array $exif   The exif numeric data
     * @param array $osmIds The list of osm_id of the places
     * @param ?float $lat    The latitude
     * @param ?float $lon    The longitude
     */
    private function setTzidFromLocation(array &$exif, array &$exifNumeric, array $osmIds, ?float $lat, ?float $lon): void
    {
        // Make sure we have some places
        if (!empty($osmIds)) {

            // Get timezone offset from places
            $query = $this->connection->getQueryBuilder();
            $query->select('name')
                ->from('memories_planet')
                ->where($query->expr()->in('osm_id', $query->createNamedParameter($osmIds, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($query->expr()->eq('admin_level', $query->expr()->literal(-7, IQueryBuilder::PARAM_INT)))
            ;

            // Get name of timezone
            $tzName = $query->executeQuery()->fetchOne();
            if ($tzName !== false && $tzName !== '') {
                $exif['LocationTZID'] = $tzName;
                return;
            } else {
                // No values use fallback
                $tzName = null;
            }
        }

        // Timezone precheck, will skip unnecessary slow Python timezone lookups in most cases whengetTimezoneFromPython is called
        // Will still be unnecessarily called occasionally if timezone wasn't in the below fields but was in a date field e.g. 
        // exiftool found one we didn't check for.
        $hasTimezone = false;
        try {
            $tzStr = $exif['OffsetTimeOriginal']
                ?? $exif['OffsetTime']
                ?? $exif['OffsetTimeDigitized']
                ?? $exif['TimeZone']
                ?? throw new \Exception();

            /** @psalm-suppress ArgumentTypeCoercion */
            $exifTz = new \DateTimeZone((string) $tzStr);
            $hasTimezone = true;
        } catch (\Exception $e) {
            $hasTimezone = false;
        } catch (\ValueError $e) {
            $hasTimezone = false;
        }

        // Fallback to Python timezonefinder if database is unavailable
        if ($lat !== null && $lon !== null && $hasTimezone === false) {
            $tzName = $this->getTimezoneFromPython($lat, $lon);
            if ($tzName !== null) {
                $exif['LocationTZID'] = $tzName;
            }
        }
    }

        /**
     * Get timezone using Python timezonefinder as a fallback.
     *
     * @param ?float $lat The latitude
     * @param ?float $lon The longitude
     *
     * @return ?string The timezone name or null if not found
     */
    private function getTimezoneFromPython(?float $lat, ?float $lon): ?string
    {
        // Validate coordinates
        if (null === $lat || null === $lon) {
            return null;
        }

        try {
            // Get timezone using Python timezonefinder
            $scriptPath = \dirname(__DIR__, 2) . '/python/findtimezone.py';
            $command = sprintf('python3 %s %f %f', escapeshellarg($scriptPath), $lat, $lon);
            $output = shell_exec($command);
            $trimmedOutput = trim($output);
            
            // Retry with python command instead of python3 if not found
            if (strpos($trimmedOutput, 'not found') !== false) {
                $command = sprintf('python %s %f %f', escapeshellarg($scriptPath), $lat, $lon);
                $output = shell_exec($command);
                $trimmedOutput = trim($output);
            }

            // Check if output contains error messages
            if (strpos($trimmedOutput, 'Error:') !== false || strpos($trimmedOutput, 'not found') !== false) {
                $this->logger->warning("Python timezone script failed: {$trimmedOutput}", ['app' => 'memories']);
                return null;
            }

            // Return the trimmed output
            if ($output && !empty($trimmedOutput)) {
                return $trimmedOutput;
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error("Error calling Python timezone script: {$e->getMessage()}", ['app' => 'memories']);
            return null;
        }
    }

    /**
     * Read coordinates from array and round to 6 decimal places.
     *
     * Modifies the EXIF Numeric array to remove invalid coordinates.
     *
     * @return (null|float)[]
     *
     * @psalm-return list{float|null, float|null}
     */
    private static function readCoord(array &$exifNumeric): array
    {
        $lat = \array_key_exists(LAT_KEY, $exifNumeric) ? round((float) $exifNumeric[LAT_KEY], 6) : null;
        $lon = \array_key_exists(LON_KEY, $exifNumeric) ? round((float) $exifNumeric[LON_KEY], 6) : null;

        // Make sure we have valid coordinates
        if (null === $lat || null === $lon
        || abs($lat) > 90 || abs($lon) > 180
        || (abs($lat) < 0.00001 && abs($lon) < 0.00001)) {
            $lat = $lon = null;
        }

        // Remove invalid coordinates
        if (null === $lat && \array_key_exists(LAT_KEY, $exifNumeric)) {
            unset($exifNumeric[LAT_KEY]);
        }
        if (null === $lon && \array_key_exists(LON_KEY, $exifNumeric)) {
            unset($exifNumeric[LON_KEY]);
        }

        return [$lat, $lon];
    }
}
