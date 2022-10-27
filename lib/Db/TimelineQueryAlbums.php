<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

trait TimelineQueryAlbums
{
    protected IDBConnection $connection;

    /** Transform only for album */
    public function transformAlbumFilter(IQueryBuilder &$query, string $uid, string $albumId)
    {
        if (!$this->hasAlbumPermission($query->getConnection(), $uid, (int) $albumId)) {
            throw new \Exception("Album {$albumId} not found");
        }

        // WHERE these are items with this album
        $query->innerJoin('m', 'photos_albums_files', 'paf', $query->expr()->andX(
            $query->expr()->eq('paf.album_id', $query->createNamedParameter($albumId)),
            $query->expr()->eq('paf.file_id', 'm.fileid'),
        ));
    }

    /** Get list of albums */
    public function getAlbums(string $uid)
    {
        $query = $this->connection->getQueryBuilder();

        // SELECT everything from albums
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'), 'count');
        $query->select('pa.*', $count)->from('photos_albums', 'pa')->where(
            $query->expr()->eq('user', $query->createNamedParameter($uid)),
        );

        // WHERE these are items with this album
        $query->leftJoin('pa', 'photos_albums_files', 'paf', $query->expr()->andX(
            $query->expr()->eq('paf.album_id', 'pa.album_id'),
        ));

        // WHERE these items are memories indexed photos
        $query->leftJoin('paf', 'memories', 'm', $query->expr()->eq('m.fileid', 'paf.file_id'));

        // WHERE these photos are in the filecache
        $query->leftJoin('m', 'filecache', 'f', $query->expr()->eq('m.fileid', 'f.fileid'));

        // GROUP and ORDER by
        $query->groupBy('pa.album_id');
        $query->orderBy('pa.created', 'DESC');
        $query->addOrderBy('pa.album_id', 'DESC'); // tie-breaker

        // FETCH all albums
        $albums = $query->executeQuery()->fetchAll();

        // Post process
        foreach ($albums as &$row) {
            $row['album_id'] = (int) $row['album_id'];
            $row['created'] = (int) $row['created'];
            $row['last_added_photo'] = (int) $row['last_added_photo'];
        }

        return $albums;
    }

    private function hasAlbumPermission(IDBConnection $conn, string $uid, int $albumId)
    {
        // Check if owner
        $query = $conn->getQueryBuilder();
        $query->select('album_id')->from('photos_albums')->where(
            $query->expr()->andX(
                $query->expr()->eq('album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)),
                $query->expr()->eq('user', $query->createNamedParameter($uid)),
            )
        );
        if (false !== $query->executeQuery()->fetchOne()) {
            return true;
        }

        // Check in collaborators
        $query = $conn->getQueryBuilder();
        $query->select('album_id')->from('photos_collaborators')->where(
            $query->expr()->andX(
                $query->expr()->eq('album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)),
                $query->expr()->eq('collaborator_id', $query->createNamedParameter($uid)),
            )
        );

        return false !== $query->executeQuery()->fetchOne();
    }
}
