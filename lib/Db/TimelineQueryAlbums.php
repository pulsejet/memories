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
        // Get album object
        $album = $this->getAlbumIfAllowed($uid, $albumId);

        // Check permission
        if (null === $album) {
            throw new \Exception("Album {$albumId} not found");
        }

        // WHERE these are items with this album
        $query->innerJoin('m', 'photos_albums_files', 'paf', $query->expr()->andX(
            $query->expr()->eq('paf.album_id', $query->createNamedParameter($album['album_id'])),
            $query->expr()->eq('paf.file_id', 'm.fileid'),
        ));
    }

    /** Get list of albums */
    public function getAlbums(string $uid, bool $shared = false)
    {
        $query = $this->connection->getQueryBuilder();

        // SELECT everything from albums
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'), 'count');
        $query->select('pa.*', $count)->from('photos_albums', 'pa');

        if ($shared) {
            $query->innerJoin('pa', $this->collaboratorsTable(), 'pc', $query->expr()->andX(
                $query->expr()->eq('pa.album_id', 'pc.album_id'),
                $query->expr()->eq('pc.collaborator_id', $query->createNamedParameter($uid)),
            ));
        } else {
            $query->where(
                $query->expr()->eq('user', $query->createNamedParameter($uid)),
            );
        }

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

    /**
     * Convert days response to months response.
     * The dayId is used to group the days into months.
     */
    public function daysToMonths(array &$days)
    {
        $months = [];
        foreach ($days as &$day) {
            $dayId = $day['dayid'];
            $time = $dayId * 86400;
            $monthid = strtotime(date('Ym', $time).'01') / 86400;

            if (empty($months) || $months[\count($months) - 1]['dayid'] !== $monthid) {
                $months[] = [
                    'dayid' => $monthid,
                    'count' => 0,
                ];
            }

            $months[\count($months) - 1]['count'] += $day['count'];
        }

        return $months;
    }

    /** Convert list of month IDs to list of dayIds */
    public function monthIdToDayIds(int $monthId)
    {
        $dayIds = [];
        $firstDay = (int) $monthId;
        $lastDay = strtotime(date('Ymt', $firstDay * 86400)) / 86400;
        for ($i = $firstDay; $i <= $lastDay; ++$i) {
            $dayIds[] = (string) $i;
        }

        return $dayIds;
    }

    /**
     * Check if a file belongs to a user through an album.
     *
     * @return bool|string owner of file
     */
    public function albumHasUserFile(string $uid, int $fileId)
    {
        $query = $this->connection->getQueryBuilder();
        $query->select('paf.owner')->from('photos_albums_files', 'paf')->where(
            $query->expr()->andX(
                $query->expr()->eq('paf.file_id', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)),
                $query->expr()->orX(
                    $query->expr()->eq('pa.album_id', 'paf.album_id'),
                    $query->expr()->eq('pc.album_id', 'paf.album_id'),
                ),
            )
        );

        // Check if user-owned album or shared album
        $query->leftJoin('paf', 'photos_albums', 'pa', $query->expr()->andX(
            $query->expr()->eq('pa.album_id', 'paf.album_id'),
            $query->expr()->eq('pa.user', $query->createNamedParameter($uid)),
        ));

        // Join to shared album
        $query->leftJoin('paf', $this->collaboratorsTable(), 'pc', $query->expr()->andX(
            $query->expr()->eq('pc.album_id', 'paf.album_id'),
            $query->expr()->eq('pc.collaborator_id', $query->createNamedParameter($uid)),
        ));

        return $query->executeQuery()->fetchOne();
    }

    /**
     * Get album if allowed. Also check if album is shared with user.
     *
     * @param string $uid     UID of CURRENT user
     * @param string $albumId $user/$name where $user is the OWNER of the album
     */
    public function getAlbumIfAllowed(string $uid, string $albumId)
    {
        // Split name and uid
        $parts = explode('/', $albumId);
        if (2 !== \count($parts)) {
            return null;
        }
        $albumUid = $parts[0];
        $albumName = $parts[1];

        // Check if owner
        $query = $this->connection->getQueryBuilder();
        $query->select('*')->from('photos_albums')->where(
            $query->expr()->andX(
                $query->expr()->eq('name', $query->createNamedParameter($albumName)),
                $query->expr()->eq('user', $query->createNamedParameter($albumUid)),
            )
        );
        $album = $query->executeQuery()->fetch();
        if (!$album) {
            return null;
        }

        // Check if user is owner
        if ($albumUid === $uid) {
            return $album;
        }

        // Check in collaborators instead
        $query = $this->connection->getQueryBuilder();
        $query->select('album_id')->from($this->collaboratorsTable())->where(
            $query->expr()->andX(
                $query->expr()->eq('album_id', $query->createNamedParameter($album['album_id'])),
                $query->expr()->eq('collaborator_id', $query->createNamedParameter($uid)),
            )
        );

        if (false !== $query->executeQuery()->fetchOne()) {
            return $album;
        }
    }

    /**
     * Get full list of fileIds in album.
     */
    public function getAlbumFiles(int $albumId)
    {
        $query = $this->connection->getQueryBuilder();
        $query->select('file_id')->from('photos_albums_files', 'paf')->where(
            $query->expr()->eq('album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT))
        );
        $query->innerJoin('paf', 'filecache', 'fc', $query->expr()->eq('fc.fileid', 'paf.file_id'));

        $fileIds = [];
        $result = $query->executeQuery();
        while ($row = $result->fetch()) {
            $fileIds[] = (int) $row['file_id'];
        }

        return $fileIds;
    }

    /** Get the name of the collaborators table */
    private function collaboratorsTable()
    {
        // https://github.com/nextcloud/photos/commit/20e3e61ad577014e5f092a292c90a8476f630355
        $appManager = \OC::$server->get(\OCP\App\IAppManager::class);
        $photosVersion = $appManager->getAppVersion('photos');
        if (version_compare($photosVersion, '2.0.1', '>=')) {
            return 'photos_albums_collabs';
        }

        return 'photos_collaborators';
    }
}
