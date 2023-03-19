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

namespace OCA\Memories\Controller;

use OCA\Memories\Errors;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

class AlbumsController extends ApiBase
{
    /**
     * @NoAdminRequired
     *
     * Get list of albums with counts of images
     */
    public function albums(int $t = 0): Http\Response
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return Errors::NotLoggedIn();
        }

        if (!$this->albumsIsEnabled()) {
            return Errors::NotEnabled('Albums');
        }

        // Run actual query
        $list = [];
        if ($t & 1) { // personal
            $list = array_merge($list, $this->timelineQuery->getAlbums($user->getUID()));
        }
        if ($t & 2) { // shared
            $list = array_merge($list, $this->timelineQuery->getAlbums($user->getUID(), true));
        }

        // Remove elements with duplicate album_id
        $seenIds = [];
        $list = array_filter($list, function ($item) use (&$seenIds) {
            if (\in_array($item['album_id'], $seenIds, true)) {
                return false;
            }
            $seenIds[] = $item['album_id'];

            return true;
        });

        return new JSONResponse($list, Http::STATUS_OK);
    }

    /**
     * @NoAdminRequired
     *
     * @UseSession
     *
     * Download an album as a zip file
     */
    public function download(string $name = ''): Http\Response
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return Errors::NotLoggedIn();
        }

        if (!$this->albumsIsEnabled()) {
            return Errors::NotEnabled('Albums');
        }

        // Get album
        $album = $this->timelineQuery->getAlbumIfAllowed($user->getUID(), $name);
        if (null === $album) {
            return Errors::NotFound("album {$name}");
        }

        // Get files
        $files = $this->timelineQuery->getAlbumFiles((int) $album['album_id']);
        if (empty($files)) {
            return Errors::NotFound("zero files in album {$name}");
        }

        // Get download handle
        $albumName = explode('/', $name)[1];
        $handle = \OCA\Memories\Controller\DownloadController::createHandle($albumName, $files);

        return new JSONResponse(['handle' => $handle], Http::STATUS_OK);
    }
}
