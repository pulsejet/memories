<?php
declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\AppInfo\Application;
use OCP\Files\File;
use OCP\IDBConnection;

class Util {
	protected IDBConnection $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

    private static function getExif($data, $field) {
        $pipes = [];
        $proc = proc_open('exiftool -b -' . $field . ' -', [
            0 => array('pipe', 'rb'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        ], $pipes);

        fwrite($pipes[0], $data);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        proc_close($proc);
        return $stdout;
    }

    public static function getDateTaken(File $file) {
        // Attempt to read exif data
        // Assume it exists in the first 256 kb of the file
        $handle = $file->fopen('rb');
        $data = stream_get_contents($handle, 256 * 1024);
        fclose($handle);

        // Try different formats
        $dt = self::getExif($data, 'DateTimeOriginal');
        if (empty($dt)) {
            $dt = self::getExif($data, 'CreateDate');
        }

        // Check if found something
        if (!empty($dt)) {
            $dt = \DateTime::createFromFormat('Y:m:d H:i:s', $dt);
            if ($dt && $dt->getTimestamp() > -5364662400) { // 1800 A.D.
                return $dt->getTimestamp();
            }
        }

        // Fall back to creation time
        $dateTaken = $file->getCreationTime();

        // Fall back to upload time
        if ($dateTaken == 0) {
            $dateTaken = $file->getUploadTime();
        }

        // Fall back to modification time
        if ($dateTaken == 0) {
            $dateTaken = $file->getMtime();
        }
        return $dateTaken;
    }

    public function processFile(File $file): void {
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
        $sql = 'SELECT COUNT(*) as e
                FROM *PREFIX*memories
                WHERE file_id = ? AND user_id = ? AND mtime = ?';
        $exists = $this->connection->executeQuery($sql, [
            $fileId, $user, $mtime,
        ], [
            \PDO::PARAM_INT, \PDO::PARAM_STR, \PDO::PARAM_INT,
        ])->fetch();
        if (intval($exists['e']) > 0) {
            return;
        }

        // Get more parameters
        $dateTaken = $this->getDateTaken($file);
        $dayId = floor($dateTaken / 86400);
        $dateTaken = gmdate('Y-m-d H:i:s', $dateTaken);

        $sql = 'INSERT
                INTO  *PREFIX*memories (day_id, date_taken, is_video, mtime, user_id, file_id)
                VALUES  (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                day_id = ?, date_taken = ?, is_video = ?, mtime = ?';
		$this->connection->executeStatement($sql, [
            $dayId, $dateTaken, $is_video, $mtime,
            $user, $fileId,
            $dayId, $dateTaken, $is_video, $mtime,
		], [
            \PDO::PARAM_INT, \PDO::PARAM_STR, \PDO::PARAM_BOOL, \PDO::PARAM_INT,
            \PDO::PARAM_STR, \PDO::PARAM_INT,
            \PDO::PARAM_INT, \PDO::PARAM_STR, \PDO::PARAM_BOOL, \PDO::PARAM_INT,
        ]);
    }

    public function deleteFile(File $file) {
        $sql = 'DELETE
                FROM *PREFIX*memories
                WHERE file_id = ?';
        $this->connection->executeStatement($sql, [$file->getId()], [\PDO::PARAM_INT]);
    }

    public function processDays(&$days) {
        foreach($days as &$row) {
            $row["day_id"] = intval($row["day_id"]);
            $row["count"] = intval($row["count"]);
        }
        return $days;
    }

    public function getDays(
        string $user,
    ): array {
        $sql = 'SELECT day_id, COUNT(file_id) AS count
                FROM `*PREFIX*memories`
                INNER JOIN `*PREFIX*filecache`
                    ON `*PREFIX*filecache`.`fileid` = `*PREFIX*memories`.`file_id`
                    AND `*PREFIX*filecache`.`path` LIKE "files/Photos/%"
                WHERE user_id=?
                GROUP BY day_id
                ORDER BY day_id DESC';
        $rows = $this->connection->executeQuery($sql, [$user], [
            \PDO::PARAM_STR,
        ])->fetchAll();
        return $this->processDays($rows);
    }

    public function getDaysFolder(int $folderId) {
        $sql = 'SELECT day_id, COUNT(file_id) AS count
                FROM `*PREFIX*memories`
                INNER JOIN `*PREFIX*filecache`
                    ON `*PREFIX*filecache`.`fileid` = `*PREFIX*memories`.`file_id`
                    AND (`*PREFIX*filecache`.`parent`=? OR `*PREFIX*filecache`.`fileid`=?)
                GROUP BY day_id
                ORDER BY day_id DESC';
        $rows = $this->connection->executeQuery($sql, [$folderId, $folderId], [
            \PDO::PARAM_INT, \PDO::PARAM_INT,
        ])->fetchAll();
        return $this->processDays($rows);
    }

    public function processDay(&$day) {
        foreach($day as &$row) {
            $row["file_id"] = intval($row["file_id"]);
            $row["is_video"] = intval($row["is_video"]);
            if (!$row["is_video"]) {
                unset($row["is_video"]);
            }
        }
        return $day;
    }

    public function getDay(
        string $user,
        int $dayId,
    ): array {
        $sql = 'SELECT file_id, *PREFIX*filecache.etag, is_video
                FROM *PREFIX*memories
                INNER JOIN *PREFIX*filecache
                    ON *PREFIX*filecache.fileid = *PREFIX*memories.file_id
                    AND `*PREFIX*filecache`.`path` LIKE "files/Photos/%"
                WHERE user_id = ? AND day_id = ?
                ORDER BY date_taken DESC';
		$rows = $this->connection->executeQuery($sql, [$user, $dayId], [
            \PDO::PARAM_STR, \PDO::PARAM_INT,
        ])->fetchAll();
        return $this->processDay($rows);
    }

    public function getDayFolder(
        int $folderId,
        int $dayId,
    ): array {
        $sql = 'SELECT file_id, *PREFIX*filecache.etag, is_video
                FROM `*PREFIX*memories`
                INNER JOIN `*PREFIX*filecache`
                    ON `*PREFIX*filecache`.`fileid` = `*PREFIX*memories`.`file_id`
                    AND (`*PREFIX*filecache`.`parent`=? OR `*PREFIX*filecache`.`fileid`=?)
                WHERE  `*PREFIX*memories`.`day_id`=?';
		$rows = $this->connection->executeQuery($sql, [$folderId, $folderId, $dayId], [
            \PDO::PARAM_INT, \PDO::PARAM_INT, \PDO::PARAM_INT,
        ])->fetchAll();
        return $this->processDay($rows);
    }
}