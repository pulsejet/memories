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

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

class AlbumsController extends ApiBase
{
    /**
     * @NoAdminRequired
     *
     * Get list of albums with counts of images
     */
    public function albums(int $t = 0): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user || !$this->albumsIsEnabled()) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // Run actual query
        $list = [];
        if ($t & 1) { // personal
            $list = array_merge($list, $this->timelineQuery->getAlbums($user->getUID()));
        }
        if ($t & 2) { // shared
            $list = array_merge($list, $this->timelineQuery->getAlbums($user->getUID(), true));
        }

        return new JSONResponse($list, Http::STATUS_OK);
    }

    /**
     * @NoAdminRequired
     *
     * Download an album as a zip file
     */
    public function download(string $name = ''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user || !$this->albumsIsEnabled()) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // Get album
        $album = $this->timelineQuery->getAlbumIfAllowed($user->getUID(), $name);
        if (null === $album) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Get files
        $files = $this->timelineQuery->getAlbumFiles($album['album_id']);
        if (empty($files)) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Get download handle
        $handle = \OCA\Memories\Controller\DownloadController::createHandle($files);

        return new JSONResponse(['handle' => $handle], Http::STATUS_OK);
    }
}
