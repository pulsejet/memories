<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\Exif;
use OCA\Memories\Service\Index;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

const DELETE_TABLES = ['memories', 'memories_livephoto', 'memories_places', 'memories_failures'];
const TRUNCATE_TABLES = ['memories_mapclusters'];

class TimelineWrite
{
    use TimelineWriteFailures;
    use TimelineWriteMap;
    use TimelineWriteOrphans;
    use TimelineWritePlaces;

    public function __construct(
        protected IDBConnection $connection,
        protected LivePhoto $livePhoto,
        protected ILockingProvider $lockingProvider,
        protected LoggerInterface $logger,
    ) {}

    /**
     * Process a file to insert Exif data into the database.
     *
     * @param File $file  File node to process
     * @param bool $lock  Lock the file before processing
     * @param bool $force Update the record even if the file has not changed
     *
     * @return bool True if the file was processed
     *
     * @throws \OCP\Lock\LockedException If the file is locked
     * @throws \OCP\DB\Exception         If the database query fails
     */
    public function processFile(
        File $file,
        bool $lock = true,
        bool $force = false,
    ): bool {
        // Check if we want to process this file
        // https://github.com/pulsejet/memories/issues/933 (zero-byte files)
        if ($file->getSize() <= 0 || !Index::isSupported($file) || !Index::isPathAllowed($file->getPath())) {
            return false;
        }

        // Check if we need to lock the file
        if ($lock) {
            $lockKey = 'memories/'.$file->getId();
            $lockType = ILockingProvider::LOCK_EXCLUSIVE;

            // Throw directly to caller if we can't get the lock
            // This way we don't release someone else's lock
            $this->lockingProvider->acquireLock($lockKey, $lockType);

            try {
                return $this->processFile($file, false, $force);
            } finally {
                $this->lockingProvider->releaseLock($lockKey, $lockType);
            }
        }

        // Get parameters
        $mtime = $file->getMtime();
        $fileId = $file->getId();
        $isvideo = Index::isVideo($file);

        // Get previous row
        $prevRow = $this->getCurrentRow($fileId);

        // Skip if all of the following:
        // - not forced
        // - the record exists
        // - the file has not changed
        // - the record is not an orphan
        if (!$force && $prevRow && ((int) $prevRow['mtime'] === $mtime) && (!(bool) $prevRow['orphan'])) {
            return false;
        }

        // Get exif data
        $exif = Exif::getExifFromFile($file);

        // Check if EXIF is blank, which is probably wrong
        if (0 === \count($exif)) {
            throw new \Exception('No EXIF data could be read');
        }

        // Check if MIMEType was not detected
        if (empty($exif['MIMEType'] ?? null)) {
            throw new \Exception('No MIMEType in EXIF data');
        }

        // Hand off if Live Photo video part
        if ($isvideo && $this->livePhoto->isVideoPart($exif)) {
            return Util::transaction(fn () => $this->livePhoto->processVideoPart($file, $exif));
        }

        // If control reaches here, it's not a Live Photo video part
        // But if prevRow exists and dayid is not set, it *was* a live video part
        // In this case delete that entry (very rare edge case)
        if ($prevRow && !\array_key_exists('dayid', $prevRow)) {
            $this->livePhoto->deleteVideoPart($file);
            $prevRow = null;
        }

        // Video parameters
        $videoDuration = round((float) ($isvideo ? ($exif['Duration'] ?? $exif['TrackDuration'] ?? 0) : 0));

        // Process location data
        // This also modifies the exif array in-place to set the LocationTZID
        // and drop the GPS data if it is not valid
        [$lat, $lon, $mapCluster] = $this->processExifLocation($fileId, $exif, $prevRow);

        // Get date parameters (after setting timezone offset)
        $dateTaken = Exif::getDateTaken($file, $exif);

        // Store the acutal epoch with the EXIF data
        $epoch = $exif['DateTimeEpoch'] = $dateTaken->getTimestamp();

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

        // Get BUID from ImageUniqueId if not present
        $buid = $prevRow ? $prevRow['buid'] : '';
        if (empty($buid)) {
            $buid = Exif::getBUID($file->getName(), $exif['ImageUniqueID'] ?? null, (int) $file->getSize());
        }

        // Get exif json
        $exifJson = $this->getExifJson($exif);

        // Parameters for insert or update
        $query = $this->connection->getQueryBuilder();
        $params = [
            'fileid' => $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
            'objectid' => $query->createNamedParameter((string) $fileId, IQueryBuilder::PARAM_STR),
            'dayid' => $query->createNamedParameter($dayId, IQueryBuilder::PARAM_INT),
            'datetaken' => $query->createNamedParameter($dateTakenStr, IQueryBuilder::PARAM_STR),
            'epoch' => $query->createNamedParameter($epoch, IQueryBuilder::PARAM_INT),
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
            'orphan' => $query->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
            'buid' => $query->createNamedParameter($buid, IQueryBuilder::PARAM_STR),
            'parent' => $query->createNamedParameter($file->getParent()->getId(), IQueryBuilder::PARAM_INT),
        ];

        // There is no easy way to UPSERT in standard SQL
        // https://stackoverflow.com/questions/15252213/sql-standard-upsert-call
        if ($prevRow) {
            $query->update('memories')
                ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ;
            foreach ($params as $key => $value) {
                $query->set($key, $value);
            }
        } else {
            $query->insert('memories')->values($params);
        }

        // Execute query
        $updated = Util::transaction(static fn () => $query->executeStatement() > 0);

        // Clear failures if successful
        if ($updated) {
            $this->clearFailures($file);
        }

        return $updated;
    }

    /**
     * Remove a file from the exif database.
     */
    public function deleteFile(File $file): void
    {
        Util::transaction(function () use ($file): void {
            // Get full record
            $query = $this->connection->getQueryBuilder();
            $record = $query->select('*')
                ->from('memories')
                ->where($query->expr()->eq('fileid', $query->createNamedParameter($file->getId(), IQueryBuilder::PARAM_INT)))
                ->executeQuery()
                ->fetch()
            ;

            // Delete all records regardless of existence
            foreach (DELETE_TABLES as $table) {
                $query = $this->connection->getQueryBuilder();
                $query->delete($table)
                    ->where($query->expr()->eq('fileid', $query->createNamedParameter($file->getId(), IQueryBuilder::PARAM_INT)))
                    ->executeStatement()
                ;
            }

            // Delete from map cluster
            if ($record && ($cid = (int) $record['mapcluster']) > 0) {
                $this->mapRemoveFromCluster($cid, (float) $record['lat'], (float) $record['lon']);
            }
        });
    }

    /**
     * Clean up the table for entries not present in filecache.
     */
    public function cleanupStale(): void
    {
        // Delete all stale records
        foreach (DELETE_TABLES as $table) {
            $clause = $this->connection->getQueryBuilder();
            $clause->select($clause->expr()->literal(1))
                ->from('filecache', 'f')
                ->where($clause->expr()->eq('f.fileid', "*PREFIX*{$table}.fileid"))
            ;

            $query = $this->connection->getQueryBuilder();
            $query->delete($table)
                ->where(SQL::notExists($query, $clause))
                ->executeStatement()
            ;
        }
    }

    /**
     * Clear the entire index. Does not need confirmation!
     */
    public function clear(): void
    {
        $p = $this->connection->getDatabasePlatform();
        foreach (array_merge(DELETE_TABLES, TRUNCATE_TABLES) as $table) {
            $this->connection->executeStatement($p->getTruncateTableSQL('*PREFIX*'.$table, false));
        }
    }

    /**
     * Get the current row for a file_id, from either table.
     */
    private function getCurrentRow(int $fileId): ?array
    {
        $fetch = function (string $table) use ($fileId): false|null|array {
            $query = $this->connection->getQueryBuilder();

            return $query->select('*')
                ->from($table)
                ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
                ->executeQuery()
                ->fetch()
            ;
        };

        return Util::transaction(static fn () => $fetch('memories') ?: $fetch('memories_livephoto') ?: null);
    }

    /**
     * Convert EXIF data to filtered JSON string.
     *
     * @param array<string, mixed> $exif EXIF data
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
            if (\array_key_exists($key, ExifFields::EXIF_FIELDS_LIST)) {
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
