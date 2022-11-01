<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Exif;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\IDBConnection;
use OCP\IPreview;

class TimelineWrite
{
    protected IDBConnection $connection;
    protected IPreview $preview;

    public function __construct(IDBConnection $connection, IPreview &$preview)
    {
        $this->connection = $connection;
        $this->preview = $preview;
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
        $query = $this->connection->getQueryBuilder();
        $query->select('fileid', 'mtime')
            ->from('memories')
            ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
        ;
        $cursor = $query->executeQuery();
        $prevRow = $cursor->fetch();
        $cursor->closeCursor();
        if ($prevRow && !$force && (int) $prevRow['mtime'] === $mtime) {
            return 1;
        }

        // Get exif data
        $exif = [];

        try {
            $exif = Exif::getExifFromFile($file);
        } catch (\Exception $e) {
        }

        // Get more parameters
        $dateTaken = Exif::getDateTaken($file, $exif);
        $dayId = floor($dateTaken / 86400);
        $dateTaken = gmdate('Y-m-d H:i:s', $dateTaken);
        [$w, $h] = Exif::getDimensions($exif);

        if ($prevRow) {
            // Update existing row
            // No need to set objectid again
            $query->update('memories')
                ->set('dayid', $query->createNamedParameter($dayId, IQueryBuilder::PARAM_INT))
                ->set('datetaken', $query->createNamedParameter($dateTaken, IQueryBuilder::PARAM_STR))
                ->set('mtime', $query->createNamedParameter($mtime, IQueryBuilder::PARAM_INT))
                ->set('isvideo', $query->createNamedParameter($isvideo, IQueryBuilder::PARAM_INT))
                ->set('w', $query->createNamedParameter($w, IQueryBuilder::PARAM_INT))
                ->set('h', $query->createNamedParameter($h, IQueryBuilder::PARAM_INT))
                ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ;
            $query->executeStatement();
        } else {
            // Try to create new row
            try {
                $query->insert('memories')
                    ->values([
                        'fileid' => $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                        'objectid' => $query->createNamedParameter((string) $fileId, IQueryBuilder::PARAM_STR),
                        'dayid' => $query->createNamedParameter($dayId, IQueryBuilder::PARAM_INT),
                        'datetaken' => $query->createNamedParameter($dateTaken, IQueryBuilder::PARAM_STR),
                        'mtime' => $query->createNamedParameter($mtime, IQueryBuilder::PARAM_INT),
                        'isvideo' => $query->createNamedParameter($isvideo, IQueryBuilder::PARAM_INT),
                        'w' => $query->createNamedParameter($w, IQueryBuilder::PARAM_INT),
                        'h' => $query->createNamedParameter($h, IQueryBuilder::PARAM_INT),
                    ])
                ;
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
        $query = $this->connection->getQueryBuilder();
        $query->delete('memories')
            ->where($query->expr()->eq('fileid', $query->createNamedParameter($file->getId(), IQueryBuilder::PARAM_INT)))
        ;
        $query->executeStatement();
    }

    /**
     * Clear the entire index. Does not need confirmation!
     *
     * @param File $file
     */
    public function clear()
    {
        $sql = $this->connection->getDatabasePlatform()->getTruncateTableSQL('`*PREFIX*memories`', false);
        $this->connection->executeStatement($sql);
    }
}
