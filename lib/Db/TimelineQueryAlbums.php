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
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'), 'count');
        $query->select('pa.*', $count)->from('photos_albums', 'pa')->where(
            $query->expr()->eq('user', $query->createNamedParameter($uid)),
        );

        // WHERE there are items with this tag
        $query->innerJoin('pa', 'photos_albums_files', 'paf', $query->expr()->andX(
            $query->expr()->eq('paf.album_id', 'pa.album_id'),
        ));

        // WHERE these items are memories indexed photos
        $query->innerJoin('paf', 'memories', 'm', $query->expr()->eq('m.fileid', 'paf.file_id'));

        // WHERE these photos are in the filecache
        $query->innerJoin('m', 'filecache', 'f', $query->expr()->eq('m.fileid', 'f.fileid'),);

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
}
