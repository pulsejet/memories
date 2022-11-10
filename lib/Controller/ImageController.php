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
        $file = $this->getUserFile((int) $id);
        if (!$file) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Get the image info
        $basic = false !== $this->request->getParam('basic', false);
        $info = $this->timelineQuery->getInfoById($file->getId(), $basic);

        // Get latest exif data if requested
        if ($this->request->getParam('current', false)) {
            $info['current'] = Exif::getExifFromFile($file);
        }

        return new JSONResponse($info, Http::STATUS_OK);
    }

    /**
     * @NoAdminRequired
     *
     * Set the exif data for a file.
     *
     * @param string fileid
     */
    public function setExif(string $id): JSONResponse
    {
        $file = $this->getUserFile((int) $id);
        if (!$file) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Check if user has permissions
        if (!$file->isUpdateable()) {
            return new JSONResponse([], Http::STATUS_FORBIDDEN);
        }

        // Get original file from body
        $exif = $this->request->getParam('raw');
        $path = $file->getStorage()->getLocalFile($file->getInternalPath());

        try {
            Exif::setExif($path, $exif);
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // Update remote file if not local
        if (!$file->getStorage()->isLocal()) {
            $file->putContent(fopen($path, 'r')); // closes the handler
        }

        // Reprocess the file
        $this->timelineWrite->processFile($file, true);

        return new JSONResponse([], Http::STATUS_OK);
    }

    /**
     * Get a full resolution PNG for editing from a file.
     */
    public function getPNG(string $id)
    {
        $file = $this->getUserFile((int) $id);
        if (!$file) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Get the image info
        $info = $this->timelineQuery->getInfoById($file->getId(), true);

        // Get the image
        $path = $file->getStorage()->getLocalFile($file->getInternalPath());
        $image = Exif::getPNG($path, $info['exif']);

        // Return the image
        $response = new Http\DataDisplayResponse($image, Http::STATUS_OK, ['Content-Type' => 'image/png']);
        $response->cacheFor(0);

        return $response;
    }
}
