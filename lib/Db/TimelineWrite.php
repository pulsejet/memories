<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Exif;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\IDBConnection;
use OCP\IPreview;

require_once __DIR__.'/../ExifFields.php';

const DELETE_TABLES = ['memories', 'memories_livephoto', 'memories_places'];
const TRUNCATE_TABLES = ['memories_mapclusters'];

class TimelineWrite
{
    use TimelineWriteMap;
    use TimelineWriteOrphans;
    use TimelineWritePlaces;
    protected IDBConnection $connection;
    protected IPreview $preview;
    protected LivePhoto $livePhoto;

    public function __construct(IDBConnection $connection)
    {
        $this->connection = $connection;
        $this->preview = \OC::$server->get(IPreview::class);
        $this->livePhoto = new LivePhoto($connection);
    }

    /**
     * Check if a file has a valid mimetype for processing.
     *
     * @return int 0 for invalid, 1 for image, 2 for video
     */
    public function getFileType(File $file): int
    {
        $mime = $file->getMimeType();
        if (\in_array($mime, Application::IMAGE_MIMES, true)) {
            // Make sure preview generator supports the mime type
            if (!$this->preview->isMimeSupported($mime)) {
                return 0;
            }

            return 1;
        }
        if (\in_array($mime, Application::VIDEO_MIMES, true)) {
            return 2;
        }

        return 0;
    }

    /**
     * Process a file to insert Exif data into the database.
     *
     * @param File $file  File node to process
     * @param int  $force 0 = none, 1 = force, 2 = force if orphan
     *
     * @return int 2 if processed, 1 if skipped, 0 if not valid
     */
    public function processFile(
        File &$file,
        int $force = 0
    ): int {
        // There is no easy way to UPSERT in a standard SQL way, so just
        // do multiple calls. The worst that can happen is more updates,
        // but that's not a big deal.
        // https://stackoverflow.com/questions/15252213/sql-standard-upsert-call

        // Check if we want to process this file
        $fileType = $this->getFileType($file);
        $isvideo = (2 === $fileType);
        if (!$fileType) {
            return 0;
        }

        // Get parameters
        $mtime = $file->getMtime();
        $fileId = $file->getId();

        // Check if need to update
        $query = $this->connection->getQueryBuilder();
        $query->select('fileid', 'mtime', 'mapcluster', 'orphan', 'lat', 'lon')
            ->from('memories')
            ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
        ;
        $cursor = $query->executeQuery();
        $prevRow = $cursor->fetch();
        $cursor->closeCursor();

        // Check in live-photo table in case this is a video part of a Live Photo
        if (!$prevRow) {
            $query = $this->connection->getQueryBuilder();
            $query->select('fileid', 'mtime')
                ->from('memories_livephoto')
                ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ;
            $cursor = $query->executeQuery();
            $prevRow = $cursor->fetch();
            $cursor->closeCursor();
        }

        // Check if a forced update is required
        $isForced = (1 === $force);
        if (2 === $force) {
            $isForced = !$prevRow
                        // Could be live video, force regardless
                        || !\array_key_exists('orphan', $prevRow)
                        // If orphan, force for sure
                        || $prevRow['orphan'];
        }

        // Skip if not forced and file has not changed
        if (!$isForced && $prevRow && ((int) $prevRow['mtime'] === $mtime)) {
            return 1;
        }

        // Get exif data
        $exif = [];

        try {
            $exif = Exif::getExifFromFile($file);
        } catch (\Exception $e) {
        }

        // Hand off if Live Photo video part
        if ($isvideo && $this->livePhoto->isVideoPart($exif)) {
            $this->livePhoto->processVideoPart($file, $exif);

            return 2;
        }

        // Video parameters
        $videoDuration = round((float) ($isvideo ? ($exif['Duration'] ?? $exif['TrackDuration'] ?? 0) : 0));

        // Process location data
        // This also modifies the exif array in-place to set the LocationTZID
        [$lat, $lon, $mapCluster] = $this->processExifLocation($fileId, $exif, $prevRow);

        // Get date parameters (after setting timezone offset)
        $dateTaken = Exif::getDateTaken($file, $exif);

        // Store the acutal epoch with the EXIF data
        $exif['DateTimeEpoch'] = $dateTaken->getTimestamp();

        // Store the date taken in the database as UTC (local date) only
        // Basically, assume everything happens in Greenwich
        $dateLocalUtc = Exif::forgetTimezone($dateTaken)->getTimestamp();
        $dateTakenStr = gmdate('Y-m-d H:i:s', $dateLocalUtc);

        // We need to use the local time in UTC for the dayId
        // This way two photos in different timezones on the same date locally
        // end up in the same dayId group
        $dayId = floor($dateLocalUtc / 86400);

        // Get size of image
        [$w, $h] = Exif::getDimensions($exif);

        // Get live photo ID of video part
        $liveid = $this->livePhoto->getLivePhotoId($file, $exif);

        // Get exif json
        $exifJson = $this->getExifJson($exif);

        // Parameters for insert or update
        $params = [
            'fileid' => $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
            'objectid' => $query->createNamedParameter((string) $fileId, IQueryBuilder::PARAM_STR),
            'dayid' => $query->createNamedParameter($dayId, IQueryBuilder::PARAM_INT),
            'datetaken' => $query->createNamedParameter($dateTakenStr, IQueryBuilder::PARAM_STR),
            'mtime' => $query->createNamedParameter($mtime, IQueryBuilder::PARAM_INT),
            'isvideo' => $query->createNamedParameter($isvideo, IQueryBuilder::PARAM_INT),
            'video_duration' => $query->createNamedParameter($videoDuration, IQueryBuilder::PARAM_INT),
            'w' => $query->createNamedParameter($w, IQueryBuilder::PARAM_INT),
            'h' => $query->createNamedParameter($h, IQueryBuilder::PARAM_INT),
            'exif' => $query->createNamedParameter($exifJson, IQueryBuilder::PARAM_STR),
            'liveid' => $query->createNamedParameter($liveid, IQueryBuilder::PARAM_STR),
            'lat' => $query->createNamedParameter($lat, IQueryBuilder::PARAM_STR),
            'lon' => $query->createNamedParameter($lon, IQueryBuilder::PARAM_STR),
            'mapcluster' => $query->createNamedParameter($mapCluster, IQueryBuilder::PARAM_INT),
        ];

        if ($prevRow) {
            // Update existing row
            // No need to set objectid again
            $query->update('memories')
                ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ;
            foreach ($params as $key => $value) {
                if ('objectid' !== $key && 'fileid' !== $key) {
                    $query->set($key, $value);
                }
            }
            $query->executeStatement();
        } else {
            // Try to create new row
            try {
                $query->insert('memories')->values($params);
                $query->executeStatement();
            } catch (\Exception $ex) {
                error_log('Failed to create memories record: '.$ex->getMessage());
            }
        }

        return 2;
    }

    /**
     * Remove a file from the exif database.
     */
    public function deleteFile(File &$file)
    {
        // Get full record
        $query = $this->connection->getQueryBuilder();
        $query->select('*')
            ->from('memories')
            ->where($query->expr()->eq('fileid', $query->createNamedParameter($file->getId(), IQueryBuilder::PARAM_INT)))
        ;
        $record = $query->executeQuery()->fetch();

        // Delete all records regardless of existence
        foreach (DELETE_TABLES as $table) {
            $query = $this->connection->getQueryBuilder();
            $query->delete($table)
                ->where($query->expr()->eq('fileid', $query->createNamedParameter($file->getId(), IQueryBuilder::PARAM_INT)))
            ;
            $query->executeStatement();
        }

        // Delete from map cluster
        if ($record && ($cid = (int) $record['mapcluster']) > 0) {
            $this->mapRemoveFromCluster($cid, (float) $record['lat'], (float) $record['lon']);
        }
    }

    /**
     * Clear the entire index. Does not need confirmation!
     *
     * @param File $file
     */
    public function clear()
    {
        $p = $this->connection->getDatabasePlatform();
        foreach (array_merge(DELETE_TABLES, TRUNCATE_TABLES) as $table) {
            $this->connection->executeStatement($p->getTruncateTableSQL('*PREFIX*'.$table, false));
        }
    }

    /**
     * Convert EXIF data to filtered JSON string.
     */
    private function getExifJson(array $exif): string
    {
        // Clean up EXIF to keep only useful metadata
        $filteredExif = [];
        foreach ($exif as $key => $value) {
            // Truncate any fields > 2048 chars
            if (\is_string($value) && \strlen($value) > 2048) {
                $value = substr($value, 0, 2048);
            }

            // Only keep fields in the whitelist
            if (\array_key_exists($key, EXIF_FIELDS_LIST)) {
                $filteredExif[$key] = $value;
            }
        }

        // Store JSON string
        $exifJson = json_encode($filteredExif);

        // Store error if data > 64kb
        if (\is_string($exifJson)) {
            if (\strlen($exifJson) > 65535) {
                $exifJson = json_encode(['error' => 'Exif data too large']);
            }
        } else {
            $exifJson = json_encode(['error' => 'Exif data encoding error']);
        }

        return $exifJson;
    }
}
