<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Varun Patil <radialapps@gmail.com>
 * @author Varun Patil <radialapps@gmail.com>
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\ClustersBackend;

use OCA\Memories\Db\AlbumsQuery;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Exceptions;
use OCA\Memories\Util;
use OCP\IRequest;

class AlbumsBackend extends Backend
{
    protected AlbumsQuery $albumsQuery;
    protected IRequest $request;
    protected TimelineQuery $tq;

    public function __construct(
        AlbumsQuery $albumsQuery,
        IRequest $request,
        TimelineQuery $tq
    ) {
        $this->albumsQuery = $albumsQuery;
        $this->request = $request;
        $this->tq = $tq;
    }

    public static function appName(): string
    {
        return 'Albums';
    }

    public static function clusterType(): string
    {
        return 'albums';
    }

    public function isEnabled(): bool
    {
        return Util::albumsIsEnabled();
    }

    public function clusterName(string $name)
    {
        return explode('/', $name)[1];
    }

    public function transformDayQuery(&$query, bool $aggregate): void
    {
        $albumId = (string) $this->request->getParam(self::clusterType());

        // Get album object
        $album = $this->albumsQuery->getIfAllowed($this->getUID(), $albumId);

        // Check permission
        if (null === $album) {
            throw new \Exception("Album {$albumId} not found");
        }

        // WHERE these are items with this album
        $query->innerJoin('m', 'photos_albums_files', 'paf', $query->expr()->andX(
            $query->expr()->eq('paf.album_id', $query->createNamedParameter($album['album_id'])),
            $query->expr()->eq('paf.file_id', 'm.fileid'),
        ));

        // Since we joined to the album, otherwise this is unsafe
        $this->tq->allowEmptyRoot();
    }

    protected function getClustersI(int $fileid = 0): array
    {
        // Run actual queries
        $list = [];

        // Personal albums
        $list = array_merge($list, $this->albumsQuery->getList(Util::getUID(), false, $fileid));
        // Shared albums
        $list = array_merge($list, $this->albumsQuery->getList(Util::getUID(), true, $fileid));

        // Remove elements with duplicate album_id
        $seenIds = [];
        $list = array_filter($list, function ($item) use (&$seenIds) {
            if (\in_array($item['album_id'], $seenIds, true)) {
                return false;
            }
            $seenIds[] = $item['album_id'];

            return true;
        });

        // Add display names for users
        $userManager = \OC::$server->get(\OCP\IUserManager::class);
        array_walk($list, function (&$item) use ($userManager) {
            $user = $userManager->get($item['user']);
            $item['user_display'] = $user ? $user->getDisplayName() : null;
        });

        // Convert $list to sequential array
        return array_values($list);
    }

    public static function getClusterId(array $cluster)
    {
        return $cluster['cluster_id'];
    }

    public function getPhotos(string $name, ?int $limit = null): array
    {
        // Get album
        $album = $this->albumsQuery->getIfAllowed($this->getUID(), $name);
        if (null === $album) {
            throw Exceptions::NotFound("album {$name}");
        }

        // Get files
        $id = (int) $album['album_id'];

        return $this->albumsQuery->getAlbumPhotos($id, $limit) ?? [];
    }

    public function sortPhotosForPreview(array &$photos)
    {
        // Do nothing, the photos are already sorted by added date desc
    }

    private function getUID(): string
    {
        return Util::isLoggedIn() ? Util::getUID() : '---';
    }
}
