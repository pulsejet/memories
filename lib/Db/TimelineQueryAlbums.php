<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\IDBConnection;

trait TimelineQueryAlbums
{
    protected IDBConnection $connection;

    public function getAlbums(string $uid)
    {
        $query = $this->connection->getQueryBuilder();

        // SELECT everything from albums
        $query->select('*')->from('photos_albums', 'pa')->where(
            $query->expr()->eq('user', $query->createNamedParameter($uid)),
        );

        // GROUP and ORDER by
        $query->orderBy('pa.created', 'DESC');
        $query->addOrderBy('pa.album_id', 'DESC'); // tie-breaker

        // FETCH all albums
        $albums = $query->executeQuery()->fetchAll();

        // Post process
        foreach ($albums as &$row) {
            $row['album_id'] = (int) $row['id'];
            $row['created'] = (int) $row['count'];
            $row['last_added_photo'] = (int) $row['last_added_photo'];
        }

        return $albums;
    }
}
