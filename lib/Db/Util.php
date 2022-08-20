<?php
declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\AppInfo\Application;
use OCP\Files\File;
use OCP\IConfig;
use OCP\IDBConnection;

class Util {
	protected IDBConnection $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

    public static function getPhotosPath(IConfig $config, string $userId) {
        $p = $config->getUserValue($userId, Application::APPNAME, 'timelinePath', '');
        if (empty($p)) {
            return '/Photos/';
        }
        return $p;
    }

    private static function getExif(File $file) {
        // Attempt to read exif data
        try {
            // Start exiftool and output to json
            $pipes = [];
            $proc = proc_open('exiftool -json -', [
                0 => array('pipe', 'rb'),
                1 => array('pipe', 'w'),
                2 => array('pipe', 'w'),
            ], $pipes);

            // Write the file to exiftool's stdin
            // Assume exif exists in the first 256 kb of the file
            $handle = $file->fopen('rb');
            stream_copy_to_stream($handle, $pipes[0], 256 * 1024);
            fclose($handle);
            fclose($pipes[0]);

            // Get output from exiftool
            $stdout = stream_get_contents($pipes[1]);

            // Clean up
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);

            // Parse the json
            $json = json_decode($stdout, true);
            if (empty($json)) {
                throw new \Exception('Could not read exif data');
            }
            return $json[0];
        } catch (\Exception $e) {
            return [];
        }
    }

    public static function getDateTaken(File $file, array $exif) {
        $dt = $exif['DateTimeOriginal'];
        if (!isset($dt) || empty($dt)) {
            $dt = $exif['CreateDate'];
        }

        // Check if found something
        if (isset($dt) && !empty($dt)) {
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
        $exif = self::getExif($file);

        // Get more parameters
        $dateTaken = $this->getDateTaken($file, $exif);
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
        IConfig $config,
        string $user,
    ): array {
        $sql = 'SELECT day_id, COUNT(file_id) AS count
                FROM `*PREFIX*memories`
                INNER JOIN `*PREFIX*filecache`
                    ON `*PREFIX*filecache`.`fileid` = `*PREFIX*memories`.`file_id`
                    AND `*PREFIX*filecache`.`path` LIKE ?
                WHERE user_id=?
                GROUP BY day_id
                ORDER BY day_id DESC';

        $path = "files" . self::getPhotosPath($config, $user) . "%";
        $rows = $this->connection->executeQuery($sql, [$path, $user], [
            \PDO::PARAM_STR, \PDO::PARAM_STR,
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
        IConfig $config,
        string $user,
        int $dayId,
    ): array {
        $sql = 'SELECT file_id, *PREFIX*filecache.etag, is_video
                FROM *PREFIX*memories
                INNER JOIN *PREFIX*filecache
                    ON *PREFIX*filecache.fileid = *PREFIX*memories.file_id
                    AND `*PREFIX*filecache`.`path` LIKE ?
                WHERE user_id = ? AND day_id = ?
                ORDER BY date_taken DESC';

        $path = "files" . self::getPhotosPath($config, $user) . "%";
		$rows = $this->connection->executeQuery($sql, [$path, $user, $dayId], [
            \PDO::PARAM_STR, \PDO::PARAM_STR, \PDO::PARAM_INT,
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