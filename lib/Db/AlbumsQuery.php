<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class AlbumsQuery
{
    protected IDBConnection $connection;

    public function __construct(IDBConnection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Get list of albums.
     *
     * @param bool $shared Whether to get shared albums
     * @param int  $fileid File to filter by
     */
    public function getList(string $uid, bool $shared = false, int $fileid = 0): array
    {
        $query = $this->connection->getQueryBuilder();

        // SELECT everything from albums
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'), 'count');
        $query->select(
            'pa.album_id',
            'pa.name',
            'pa.user',
            'pa.created',
            'pa.created',
            'pa.location',
            'pa.last_added_photo',
            $count
        )->from('photos_albums', 'pa');

        if ($shared) {
            $ids = $this->getSelfCollaborators($uid);
            $query->innerJoin('pa', $this->collaboratorsTable(), 'pc', $query->expr()->andX(
                $query->expr()->eq('pa.album_id', 'pc.album_id'),
                $query->expr()->in('pc.collaborator_id', $query->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)),
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

        // WHERE these albums contain fileid if specified
        if ($fileid) {
            $fSq = $this->connection->getQueryBuilder()
                ->select('paf.file_id')
                ->from('photos_albums_files', 'paf')
                ->where($query->expr()->andX(
                    $query->expr()->eq('paf.album_id', 'pa.album_id'),
                    $query->expr()->eq('paf.file_id', $query->createNamedParameter($fileid, IQueryBuilder::PARAM_INT)),
                ))
                ->getSQL()
            ;
            $query->andWhere($query->createFunction("EXISTS ({$fSq})"));
        }

        // FETCH all albums
        $albums = $query->executeQuery()->fetchAll();

        // Post process
        foreach ($albums as &$row) {
            $row['cluster_id'] = $row['user'].'/'.$row['name'];
            $row['album_id'] = (int) $row['album_id'];
            $row['created'] = (int) $row['created'];
            $row['last_added_photo'] = (int) $row['last_added_photo'];
        }

        return $albums;
    }

    /**
     * Check if an album has a file.
     *
     * @return bool|string owner of file
     */
    public function hasFile(int $albumId, int $fileId)
    {
        $query = $this->connection->getQueryBuilder();
        $query->select('owner')->from('photos_albums_files')->where(
            $query->expr()->andX(
                $query->expr()->eq('file_id', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)),
                $query->expr()->eq('album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)),
            )
        );

        return $query->executeQuery()->fetchOne();
    }

    /**
     * Check if a file belongs to a user through an album.
     *
     * @return bool|string owner of file
     */
    public function userHasFile(string $uid, int $fileId)
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
        $ids = $this->getSelfCollaborators($uid);
        $query->leftJoin('paf', $this->collaboratorsTable(), 'pc', $query->expr()->andX(
            $query->expr()->eq('pc.album_id', 'paf.album_id'),
            $query->expr()->in('pc.collaborator_id', $query->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)),
        ));

        return $query->executeQuery()->fetchOne();
    }

    /**
     * Get album if allowed. Also check if album is shared with user.
     *
     * @param string $uid     UID of CURRENT user
     * @param string $albumId $user/$name where $user is the OWNER of the album
     */
    public function getIfAllowed(string $uid, string $albumId): ?array
    {
        $album = null;

        // Split name and uid
        $parts = explode('/', $albumId);
        if (2 === \count($parts)) {
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
        }

        // Album not found: it could be a link token at best
        if (!$album) {
            return $this->getAlbumByLink($albumId);
        }

        // Check if user is owner
        if ($albumUid === $uid) {
            return $album;
        }

        // Check in collaborators instead
        $albumNumId = (int) $album['album_id'];
        if ($this->userIsCollaborator($uid, $albumNumId)) {
            return $album;
        }

        return null;
    }

    /**
     * Check if user is a collaborator by numeric ID.
     * Also checks if a group is a collaborator.
     * Does not check if the user is the owner.
     *
     * @param string $uid     User ID
     * @param int    $albumId Album ID (numeric)
     */
    public function userIsCollaborator(string $uid, int $albumId): bool
    {
        $query = $this->connection->getQueryBuilder();
        $ids = $this->getSelfCollaborators($uid);
        $query->select('album_id')->from($this->collaboratorsTable())->where(
            $query->expr()->andX(
                $query->expr()->eq('album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)),
                $query->expr()->in('collaborator_id', $query->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)),
            )
        );

        return false !== $query->executeQuery()->fetchOne();
    }

    /**
     * Get album object by token.
     * Returns false if album link does not exist.
     */
    public function getAlbumByLink(string $token): ?array
    {
        $query = $this->connection->getQueryBuilder();
        $query->select('*')->from('photos_albums', 'pa')
            ->innerJoin('pa', $this->collaboratorsTable(), 'pc', $query->expr()->andX(
                $query->expr()->eq('pc.album_id', 'pa.album_id'),
                $query->expr()->eq('collaborator_id', $query->createNamedParameter($token)),
                $query->expr()->eq('collaborator_type', $query->expr()->literal(3, \PDO::PARAM_INT)), // = TYPE_LINK
            ))
        ;

        return $query->executeQuery()->fetch() ?: null;
    }

    /**
     * Get list of photos in album.
     */
    public function getAlbumPhotos(int $albumId, ?int $limit): array
    {
        $query = $this->connection->getQueryBuilder();

        // SELECT all files
        $query->select('file_id')->from('photos_albums_files', 'paf');

        // WHERE they are in this album
        $query->where($query->expr()->eq('album_id', $query->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)));

        // AND in the filecache
        $query->innerJoin('paf', 'filecache', 'fc', $query->expr()->eq('fc.fileid', 'paf.file_id'));

        // Do not check if these files are indexed in memories
        // This is since this function is called for downloads
        // so funky things might happen if non-indexed files were
        // added throught the Photos app

        // ORDER by the id of the paf i.e. the order in which they were added
        $query->orderBy('paf.album_file_id', 'DESC');

        // LIMIT the results
        if (null !== $limit) {
            $query->setMaxResults($limit);
        }

        $result = $query->executeQuery()->fetchAll();

        foreach ($result as &$row) {
            $row['fileid'] = (int) $row['file_id'];
        }

        return $result;
    }

    /** Get list of collaborator ids including user id and groups */
    private function getSelfCollaborators(string $uid)
    {
        // Get groups for the user
        $groupManager = \OC::$server->get(\OCP\IGroupManager::class);
        $user = \OC::$server->get(\OCP\IUserManager::class)->get($uid);
        $groups = $groupManager->getUserGroupIds($user);

        // And albums shared with user
        $groups[] = $uid;

        return $groups;
    }

    /**
     * Get the name of the collaborators table.
     */
    private function collaboratorsTable(): string
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
