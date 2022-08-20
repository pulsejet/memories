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
     */
    public function processFile(File &$file): void {
        // There is no easy way to UPSERT in a standard SQL way, so just
        // do multiple calls. The worst that can happen is more updates,
        // but that's not a big deal.
        // https://stackoverflow.com/questions/15252213/sql-standard-upsert-call

        // Check if we want to process this file
        $mime = $file->getMimeType();
        $is_image = in_array($mime, Application::IMAGE_MIMES);
        $is_video = in_array($mime, Application::VIDEO_MIMES);
        if (!$is_image && !$is_video) {
            return;
        }

        // Get parameters
        $mtime = $file->getMtime();
        $user = $file->getOwner()->getUID();
        $fileId = $file->getId();

        // Check if need to update
        $sql = 'SELECT `mtime`
                FROM *PREFIX*memories
                WHERE file_id = ? AND user_id = ?';
        $prevRow = $this->connection->executeQuery($sql, [
            $fileId, $user,
        ], [
            \PDO::PARAM_INT, \PDO::PARAM_STR,
        ])->fetch();
        if ($prevRow && intval($prevRow['mtime']) === $mtime) {
            return;
        }

        // Get exif data
        $exif = [];
        try {
            $exif = Exif::getExifFromFile($file);
        } catch (\Exception) {}

        // Get more parameters
        $dateTaken = Exif::getDateTaken($file, $exif);
        $dayId = floor($dateTaken / 86400);
        $dateTaken = gmdate('Y-m-d H:i:s', $dateTaken);

        if ($prevRow) {
            // Update existing row
            $sql = 'UPDATE *PREFIX*memories
                    SET day_id = ?, date_taken = ?, is_video = ?, mtime = ?
                    WHERE user_id = ? AND file_id = ?';
            $this->connection->executeStatement($sql, [
                $dayId, $dateTaken, $is_video, $mtime,
                $user, $fileId,
            ], [
                \PDO::PARAM_INT, \PDO::PARAM_STR, \PDO::PARAM_BOOL, \PDO::PARAM_INT,
                \PDO::PARAM_STR, \PDO::PARAM_INT,
            ]);
        } else {
            // Create new row
            $sql = 'INSERT
                    INTO  *PREFIX*memories (day_id, date_taken, is_video, mtime, user_id, file_id)
                    VALUES  (?, ?, ?, ?, ?, ?)';
            $this->connection->executeStatement($sql, [
                $dayId, $dateTaken, $is_video, $mtime,
                $user, $fileId,
            ], [
                \PDO::PARAM_INT, \PDO::PARAM_STR, \PDO::PARAM_BOOL, \PDO::PARAM_INT,
                \PDO::PARAM_STR, \PDO::PARAM_INT,
            ]);
        }
    }

    /**
     * Remove a file from the exif database
     * @param File $file
     */
    public function deleteFile(File &$file) {
        $sql = 'DELETE
                FROM *PREFIX*memories
                WHERE file_id = ?';
        $this->connection->executeStatement($sql, [$file->getId()], [\PDO::PARAM_INT]);
    }
}