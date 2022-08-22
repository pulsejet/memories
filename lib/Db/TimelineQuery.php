<?php
declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\Exif;
use OCP\IConfig;
use OCP\IDBConnection;

class TimelineQuery {
	protected IDBConnection $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

    /**
     * Process the days response
     * @param array $days
     */
    private function processDays(&$days) {
        foreach($days as &$row) {
            $row["dayid"] = intval($row["dayid"]);
            $row["count"] = intval($row["count"]);
        }
        return $days;
    }

    /**
     * Get the days response from the database for the timeline
     * @param IConfig $config
     * @param string $userId
     */
    public function getDays(
        IConfig &$config,
        string &$user): array {

        $sql = 'SELECT `*PREFIX*memories`.`dayid`, COUNT(`*PREFIX*memories`.`fileid`) AS count
                FROM `*PREFIX*memories`
                INNER JOIN `*PREFIX*filecache`
                    ON `*PREFIX*filecache`.`fileid` = `*PREFIX*memories`.`fileid`
                    AND `*PREFIX*filecache`.`path` LIKE ?
                WHERE uid=?
                GROUP BY `*PREFIX*memories`.`dayid`
                ORDER BY `*PREFIX*memories`.`dayid` DESC';

        $path = "files" . Exif::getPhotosPath($config, $user) . "%";
        $rows = $this->connection->executeQuery($sql, [$path, $user], [
            \PDO::PARAM_STR, \PDO::PARAM_STR,
        ])->fetchAll();
        return $this->processDays($rows);
    }

    /**
     * Get the days response from the database for one folder
     * @param int $folderId
     */
    public function getDaysFolder(int &$folderId) {
        $sql = 'SELECT `*PREFIX*memories`.`dayid`, COUNT(`*PREFIX*memories`.`fileid`) AS count
                FROM `*PREFIX*memories`
                INNER JOIN `*PREFIX*filecache`
                    ON `*PREFIX*filecache`.`fileid` = `*PREFIX*memories`.`fileid`
                    AND (`*PREFIX*filecache`.`parent`=? OR `*PREFIX*filecache`.`fileid`=?)
                GROUP BY dayid
                ORDER BY dayid DESC';
        $rows = $this->connection->executeQuery($sql, [$folderId, $folderId], [
            \PDO::PARAM_INT, \PDO::PARAM_INT,
        ])->fetchAll();
        return $this->processDays($rows);
    }

    /**
     * Process the single day response
     * @param array $day
     */
    private function processDay(&$day) {
        foreach($day as &$row) {
            $row["fileid"] = intval($row["fileid"]);
            $row["isvideo"] = intval($row["isvideo"]);
            if (!$row["isvideo"]) {
                unset($row["isvideo"]);
            }
        }
        return $day;
    }

    /**
     * Get a day response from the database for the timeline
     * @param IConfig $config
     * @param string $userId
     * @param int $dayId
     */
    public function getDay(
        IConfig &$config,
        string &$user,
        int &$dayId): array {

        $sql = 'SELECT `*PREFIX*memories`.`fileid`, *PREFIX*filecache.etag, `*PREFIX*memories`.`isvideo`
                FROM *PREFIX*memories
                INNER JOIN *PREFIX*filecache
                    ON `*PREFIX*filecache`.`fileid` = `*PREFIX*memories`.`fileid`
                    AND `*PREFIX*filecache`.`path` LIKE ?
                WHERE `*PREFIX*memories`.`uid` = ? AND `*PREFIX*memories`.`dayid` = ?
                ORDER BY `*PREFIX*memories`.`datetaken` DESC';

        $path = "files" . Exif::getPhotosPath($config, $user) . "%";
		$rows = $this->connection->executeQuery($sql, [$path, $user, $dayId], [
            \PDO::PARAM_STR, \PDO::PARAM_STR, \PDO::PARAM_INT,
        ])->fetchAll();
        return $this->processDay($rows);
    }

    /**
     * Get a day response from the database for one folder
     * @param int $folderId
     * @param int $dayId
     */
    public function getDayFolder(
        int &$folderId,
        int &$dayId): array {

        $sql = 'SELECT `*PREFIX*memories`.`fileid`, `*PREFIX*filecache`.`etag`, `*PREFIX*memories`.`isvideo`
                FROM `*PREFIX*memories`
                INNER JOIN `*PREFIX*filecache`
                    ON `*PREFIX*filecache`.`fileid` = `*PREFIX*memories`.`fileid`
                    AND (`*PREFIX*filecache`.`parent`=? OR `*PREFIX*filecache`.`fileid`=?)
                WHERE  `*PREFIX*memories`.`dayid`=?';
		$rows = $this->connection->executeQuery($sql, [$folderId, $folderId, $dayId], [
            \PDO::PARAM_INT, \PDO::PARAM_INT, \PDO::PARAM_INT,
        ])->fetchAll();
        return $this->processDay($rows);
    }
}