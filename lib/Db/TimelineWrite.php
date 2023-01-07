<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Exif;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\IPreparedStatement;
use OCP\Files\File;
use OCP\IDBConnection;
use OCP\IPreview;

class TimelineWrite
{
    protected IDBConnection $connection;
    protected IPreview $preview;
    protected LivePhoto $livePhoto;
    protected IPreparedStatement $selectMemories;
    protected IPreparedStatement $selectMemoriesLivePhoto;
    protected IPreparedStatement $updateMemories;
    protected IPreparedStatement $insertMemories;
    protected IPreparedStatement $deleteMemories;
    protected IPreparedStatement $deleteMemoriesLivePhoto;

    public function __construct(IDBConnection $connection)
    {
        $this->connection = $connection;
        $this->preview = \OC::$server->get(IPreview::class);
        $this->livePhoto = new LivePhoto($connection);

        $selectBuilder = function ($table) : IPreparedStatement {
            $query = $this->connection->getQueryBuilder();
            $sql = $query->select('fileid', 'mtime')
                ->from($table)
                ->where($query->expr()->eq('fileid', $query->createParameter('fileid')))
                ->setMaxResults(1)
                ->getSQL();
            return $this->connection->prepare($sql);
        };
        $this->selectMemories = $selectBuilder('memories');
        $this->selectMemoriesLivePhoto = $selectBuilder('memories_livephoto');

        // Update existing row
        // No need to set objectid again
        $query= $this->connection->getQueryBuilder();
        $sql = $query->update('memories')
            ->set('dayid', $query->createParameter('dayid'))
            ->set('datetaken', $query->createParameter('datetaken'))
            ->set('mtime', $query->createParameter('mtime'))
            ->set('isvideo', $query->createParameter('isvideo'))
            ->set('video_duration', $query->createParameter('video_duration'))
            ->set('w', $query->createParameter('w'))
            ->set('h', $query->createParameter('h'))
            ->set('exif', $query->createParameter('exif'))
            ->set('liveid', $query->createParameter('liveid'))
            ->where($query->expr()->eq('fileid', $query->createParameter('fileid')))
            ->setMaxResults(1)
            ->getSQL();
        $this->updateMemories = $this->connection->prepare($sql);

        // Create new row
        $query = $this->connection->getQueryBuilder();
        $sql = $query->insert('memories')
            ->values([
                'fileid' => $query->createParameter('fileid'),
                'objectid' => $query->createParameter('objectid'),
                'dayid' => $query->createParameter('dayid'),
                'datetaken' => $query->createParameter('datetaken'),
                'mtime' => $query->createParameter('mtime'),
                'isvideo' => $query->createParameter('isvideo'),
                'video_duration' => $query->createParameter('video_duration'),
                'w' => $query->createParameter('w'),
                'h' => $query->createParameter('h'),
                'exif' => $query->createParameter('exif'),
                'liveid' => $query->createParameter('liveid')
            ])
            ->getSQL();
        $this->insertMemories = $this->connection->prepare($sql);

        $deleteBuilder = function ($table) : IPreparedStatement {
            $query = $this->connection->getQueryBuilder();
            $sql = $query->delete($table)
                ->where($query->expr()->eq('fileid', $query->createParameter('fileid')))
                ->setMaxResults(1)
                ->getSQL();
            return $this->connection->prepare($sql);
        };
        $this->deleteMemories = $deleteBuilder('memories');
        $this->deleteMemoriesLivePhoto = $deleteBuilder('memories_livephoto');
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
     * @return int 2 if processed, 1 if skipped, 0 if not valid
     */
    public function processFile(
        File &$file,
        bool $force = false
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
        $this->selectMemories->bindValue('fileid', $fileId, IQueryBuilder::PARAM_INT);
        $cursor = $this->selectMemories->execute();
        $prevRow = $cursor->fetch();
        $cursor->closeCursor();

        // Check in live-photo table in case this is a video part of a live photo
        if (!$prevRow) {
            $this->selectMemoriesLivePhoto->bindValue('fileid', $fileId, IQueryBuilder::PARAM_INT);
            $cursor = $this->selectMemoriesLivePhoto->execute();
            $prevRow = $cursor->fetch();
            $cursor->closeCursor();
        }

        if ($prevRow && !$force && (int) $prevRow['mtime'] === $mtime) {
            return 1;
        }

        // Get exif data
        $exif = [];

        try {
            $exif = Exif::getExifFromFile($file);
        } catch (\Exception $e) {
        }

        // Hand off if live photo video part
        if ($isvideo && $this->livePhoto->isVideoPart($exif)) {
            $this->livePhoto->processVideoPart($file, $exif);

            return 2;
        }

        // Get more parameters
        $dateTaken = Exif::getDateTaken($file, $exif);
        $dayId = floor($dateTaken / 86400);
        $dateTaken = gmdate('Y-m-d H:i:s', $dateTaken);
        [$w, $h] = Exif::getDimensions($exif);
        $liveid = $this->livePhoto->getLivePhotoId($exif);

        // Video parameters
        $videoDuration = 0;
        if ($isvideo) {
            $videoDuration = round((float) ($exif['Duration'] ?? $exif['TrackDuration'] ?? 0));
        }

        // Clean up EXIF to keep only useful metadata
        foreach ($exif as $key => &$value) {
            // Truncate any fields > 2048 chars
            if (\is_string($value) && \strlen($value) > 2048) {
                $exif[$key] = substr($value, 0, 2048);
            }

            // These are huge and not needed
            if (str_starts_with($key, 'Nikon') || str_starts_with($key, 'QuickTime')) {
                unset($exif[$key]);
            }
        }

        // Store JSON string
        $exifJson = json_encode($exif);

        // Store error if data > 64kb
        if (\is_string($exifJson)) {
            if (\strlen($exifJson) > 65535) {
                $exifJson = json_encode(['error' => 'Exif data too large']);
            }
        } else {
            $exifJson = json_encode(['error' => 'Exif data encoding error']);
        }

        $query = $prevRow ? $this->updateMemories : $this->insertMemories;
        $query->bindValue('fileid', $fileId, IQueryBuilder::PARAM_INT);
        $query->bindValue('objectid', (string) $fileId, IQueryBuilder::PARAM_STR);
        $query->bindValue('dayid', $dayId, IQueryBuilder::PARAM_INT);
        $query->bindValue('datetaken', $dateTaken, IQueryBuilder::PARAM_STR);
        $query->bindValue('mtime', $mtime, IQueryBuilder::PARAM_INT);
        $query->bindValue('isvideo', $isvideo, IQueryBuilder::PARAM_INT);
        $query->bindValue('video_duration', $videoDuration, IQueryBuilder::PARAM_INT);
        $query->bindValue('w', $w, IQueryBuilder::PARAM_INT);
        $query->bindValue('h', $h, IQueryBuilder::PARAM_INT);
        $query->bindValue('exif', $exifJson, IQueryBuilder::PARAM_STR);
        $query->bindValue('liveid', $liveId, IQueryBuilder::PARAM_STR);

        try {
            $query->execute();
        } catch (\Exception $ex) {
            error_log('Failed to write memories record: '.$ex->getMessage());
        }

        return 2;
    }

    /**
     * Remove a file from the exif database.
     */
    public function deleteFile(File &$file)
    {
        $deleteFrom = function ($query) use (&$file) {
            $query->bindValue('fileid', $file->getId(), IQueryBuilder::PARAM_INT);
            $query->execute();
        };
        $deleteFrom($this->deleteMemories);
        $deleteFrom($this->deleteMemoriesLivePhoto);
    }

    /**
     * Clear the entire index. Does not need confirmation!
     *
     * @param File $file
     */
    public function clear()
    {
        $p = $this->connection->getDatabasePlatform();
        $t1 = $p->getTruncateTableSQL('`*PREFIX*memories`', false);
        $t2 = $p->getTruncateTableSQL('`*PREFIX*memories_livephoto`', false);
        $this->connection->executeStatement("{$t1}; {$t2}");
    }
}
