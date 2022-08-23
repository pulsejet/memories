<?php
declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Exif;
use OCP\Files\File;
use OCP\IDBConnection;

class TimelineWrite {
	protected IDBConnection $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

    /**
     * Process a file to insert Exif data into the database
     * @param File $file
     * @return int 2 if processed, 1 if skipped, 0 if not valid
     */
    public function processFile(File &$file): int {
        // There is no easy way to UPSERT in a standard SQL way, so just
        // do multiple calls. The worst that can happen is more updates,
        // but that's not a big deal.
        // https://stackoverflow.com/questions/15252213/sql-standard-upsert-call

        // Check if we want to process this file
        $mime = $file->getMimeType();
        $is_image = in_array($mime, Application::IMAGE_MIMES);
        $isvideo = in_array($mime, Application::VIDEO_MIMES);
        if (!$is_image && !$isvideo) {
            return 0;
        }

        // Get parameters
        $mtime = $file->getMtime();
        $user = $file->getOwner()->getUID();
        $fileId = $file->getId();

        // Check if need to update
        $sql = 'SELECT `fileid`, `mtime`
                FROM *PREFIX*memories
                WHERE `fileid` = ? AND `uid` = ?';
        $prevRow = $this->connection->executeQuery($sql, [
            $fileId, $user,
        ], [
            \PDO::PARAM_INT, \PDO::PARAM_STR,
        ])->fetch();
        if ($prevRow && intval($prevRow['mtime']) === $mtime) {
            return 1;
        }

        // Get exif data
        $exif = [];
        try {
            $exif = Exif::getExifFromFile($file);
        } catch (\Exception $e) {}

        // Get more parameters
        $dateTaken = Exif::getDateTaken($file, $exif);
        $dayId = floor($dateTaken / 86400);
        $dateTaken = gmdate('Y-m-d H:i:s', $dateTaken);

        if ($prevRow) {
            // Update existing row
            $sql = 'UPDATE *PREFIX*memories
                    SET `dayid` = ?, `datetaken` = ?, `isvideo` = ?, `mtime` = ?
                    WHERE `uid` = ? AND `fileid` = ?';
            $this->connection->executeStatement($sql, [
                $dayId, $dateTaken, $isvideo, $mtime,
                $user, $fileId,
            ], [
                \PDO::PARAM_INT, \PDO::PARAM_STR, \PDO::PARAM_BOOL, \PDO::PARAM_INT,
                \PDO::PARAM_STR, \PDO::PARAM_INT,
            ]);
        } else {
            // Try to create new row
            try {
                $sql = 'INSERT
                        INTO  *PREFIX*memories (`dayid`, `datetaken`, `isvideo`, `mtime`, `uid`, `fileid`)
                        VALUES  (?, ?, ?, ?, ?, ?)';
                $this->connection->executeStatement($sql, [
                    $dayId, $dateTaken, $isvideo, $mtime,
                    $user, $fileId,
                ], [
                    \PDO::PARAM_INT, \PDO::PARAM_STR, \PDO::PARAM_BOOL, \PDO::PARAM_INT,
                    \PDO::PARAM_STR, \PDO::PARAM_INT,
                ]);
            } catch (\Exception $ex) {
                error_log("Failed to create memories record: " . $ex->getMessage());
            }
        }

        return 2;
    }

    /**
     * Remove a file from the exif database
     * @param File $file
     */
    public function deleteFile(File &$file) {
        $sql = 'DELETE
                FROM *PREFIX*memories
                WHERE `fileid` = ?';
        $this->connection->executeStatement($sql, [$file->getId()], [\PDO::PARAM_INT]);
    }
}