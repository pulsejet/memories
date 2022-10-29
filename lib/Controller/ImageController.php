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

use OCA\Memories\Exif;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

class ImageController extends ApiBase
{
    /**
     * @NoAdminRequired
     *
     * Get image info for one file
     *
     * @param string fileid
     */
    public function info(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());

        // Check for permissions and get numeric Id
        $file = $userFolder->getById((int) $id);
        if (0 === \count($file)) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }
        $file = $file[0];

        // Get the image info
        $info = $this->timelineQuery->getInfoById($file->getId());

        return new JSONResponse($info, Http::STATUS_OK);
    }

    /**
     * @NoAdminRequired
     *
     * Change exif data for one file
     *
     * @param string fileid
     */
    public function edit(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());

        // Check for permissions and get numeric Id
        $file = $userFolder->getById((int) $id);
        if (0 === \count($file)) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }
        $file = $file[0];

        // Check if user has permissions
        if (!$file->isUpdateable()) {
            return new JSONResponse([], Http::STATUS_FORBIDDEN);
        }

        // Get new date from body
        $body = $this->request->getParams();
        if (!isset($body['date'])) {
            return new JSONResponse(['message' => 'Missing date'], Http::STATUS_BAD_REQUEST);
        }

        // Make sure the date is valid
        try {
            Exif::parseExifDate($body['date']);
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        // Update date
        try {
            $res = Exif::updateExifDate($file, $body['date']);
            if (false === $res) {
                return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
            }
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // Reprocess the file
        $this->timelineWrite->processFile($file, true);

        return $this->info($id);
    }
}
