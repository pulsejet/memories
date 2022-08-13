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

    private function getDateTaken($file) {
        $dateTaken = $file->getCreationTime();
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

        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('betterphotos')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($user)))
            ->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($file->getId())))
            ->setMaxResults(1);;
        $result = $qb->executeQuery();
        $row = $result->fetch();

        if ($row !== false) {
            if ($update) {
                $qb = $this->connection->getQueryBuilder();
                $qb->update('betterphotos')
                    ->set('date_taken', $qb->createNamedParameter($this->getDateTaken($file), IQueryBuilder::PARAM_INT))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($row['id'])));
                $qb->executeStatement();
            }

            return;
        }

        $qb->insert('betterphotos')
            ->setValue('user_id', $qb->createNamedParameter($user))
            ->setValue('file_id', $qb->createNamedParameter($file->getId()))
            ->setValue('date_taken', $qb->createNamedParameter($this->getDateTaken($file), IQueryBuilder::PARAM_INT));
        $qb->executeStatement();
    }

    public function deleteFile(File $file) {
        $qb = $this->connection->getQueryBuilder();
        $qb->delete('betterphotos')
            ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($file->getId())));
        $qb->executeStatement();
    }

    private static function getListQuery(
        IDBConnection $connection,
        string $user,
    ) {
        $qb = $connection->getQueryBuilder();
        $qb->select('file_id', 'date_taken')
            ->from('betterphotos')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($user)))
            ->orderBy('date_taken', 'DESC')
            ->setMaxResults(100);
        return $qb;
    }

    public static function getList(
        IDBConnection $connection,
        string $user,
    ): array {
        $qb = self::getListQuery($connection, $user);
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        return $rows;
    }

    public static function getAfter(
        IDBConnection $connection,
        string $user,
        int $time,
    ): array {
        $qb = self::getListQuery($connection, $user);
        $qb->andWhere($qb->expr()->gte('date_taken', $qb->createNamedParameter($time, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        return $rows;
    }

    public static function getBefore(
        IDBConnection $connection,
        string $user,
        int $time,
    ): array {
        $qb = self::getListQuery($connection, $user);
        $qb->andWhere($qb->expr()->lte('date_taken', $qb->createNamedParameter($time, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        return $rows;
    }
}