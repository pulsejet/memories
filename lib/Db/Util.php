<?php
declare(strict_types=1);

namespace OCA\BetterPhotos\Db;

use OCP\Files\File;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IPreview;

class Util {
	protected IPreview $previewGenerator;
	protected IDBConnection $connection;

	public function __construct(IPreview $previewGenerator,
								IDBConnection $connection) {
		$this->previewGenerator = $previewGenerator;
		$this->connection = $connection;
	}

    public static function getDateTaken($file) {
        // Attempt to read exif data
        $exif = exif_read_data($file->fopen('rb'));
		$dt = $exif['DateTimeOriginal'];
		if ($dt) {
			$dt = \DateTime::createFromFormat('Y:m:d H:i:s', $dt);
			if ($dt) {
				return $dt->getTimestamp();
			}
		}

        // Fall back to creation time
        $dateTaken = $file->getCreationTime();

        // Fall back to modification time
        if ($dateTaken == 0) {
            $dateTaken = $file->getMtime();
        }
        return $dateTaken;
    }

    public function processFile(string $user, File $file, bool $update): void {
        $mime = $file->getMimeType();
        if (!str_starts_with($mime, 'image/')) {
            return;
        }

        if (!$this->previewGenerator->isMimeSupported($file->getMimeType())) {
            return;
        }

        // Get parameters
        $fileId = $file->getId();
        $dateTaken = $this->getDateTaken($file);
        $dayId = floor($dateTaken / 86400);

        // Insert or update file
        // todo: update dateTaken and dayId if needed
        $sql = 'INSERT IGNORE
                INTO  oc_betterphotos (user_id, file_id, date_taken, day_id)
                VALUES  (?, ?, ?, ?)';
		$res = $this->connection->executeStatement($sql, [
            $user, $fileId, $dateTaken, $dayId,
		]);

        // Update day table
        if ($res === 1) {
            $sql = 'INSERT
                    INTO  oc_betterphotos_day (user_id, day_id, count)
                    VALUES  (?, ?, 1)
                    ON DUPLICATE KEY
                    UPDATE  count = count + 1';
            $this->connection->executeStatement($sql, [
                $user, $dayId,
            ]);
        }
    }

    public function deleteFile(File $file) {
        $sql = 'DELETE
                FROM oc_betterphotos
                WHERE file_id = ?
                RETURNING *';
        $res = $this->connection->executeQuery($sql, [$file->getId()], [\PDO::PARAM_INT]);
        $rows = $res->fetchAll();

        foreach ($rows as $row) {
            $dayId = $row['day_id'];
            $userId = $row['user_id'];
            $sql = 'UPDATE oc_betterphotos_day
                    SET count = count - 1
                    WHERE user_id = ? AND day_id = ?';
            $this->connection->executeStatement($sql, [$userId, $dayId], [
                \PDO::PARAM_STR, \PDO::PARAM_INT,
            ]);
        }
    }

    public static function getDays(
        IDBConnection $connection,
        string $user,
    ): array {
        $qb = $connection->getQueryBuilder();
        $qb->select('day_id', 'count')
            ->from('betterphotos_day')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($user)))
            ->orderBy('day_id', 'DESC');
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        return $rows;
    }

    public static function getDay(
        IDBConnection $connection,
        string $user,
        int $dayId,
    ): array {
        $qb = $connection->getQueryBuilder();
        $qb->select('file_id')
            ->from('betterphotos')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($user)))
            ->andWhere($qb->expr()->eq('day_id', $qb->createNamedParameter($dayId)))
            ->orderBy('date_taken', 'DESC');
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        return $rows;
    }
}