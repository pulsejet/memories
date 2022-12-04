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

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Db\TimelineWrite;
use OCA\Memories\Exif;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;

class ImageController extends ApiBase
{
    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * Get preview of image
     */
    public function preview(
        int $id,
        int $x = 32,
        int $y = 32,
        bool $a = false,
        string $mode = 'fill'
    ) {
        if (-1 === $id || 0 === $x || 0 === $y) {
            return new JSONResponse([
                'message' => 'Invalid parameters',
            ], Http::STATUS_BAD_REQUEST);
        }

        $file = $this->getUserFile($id);
        if (!$file) {
            return new JSONResponse([
                'message' => 'File not found',
            ], Http::STATUS_NOT_FOUND);
        }

        try {
            $preview = \OC::$server->getPreviewManager()->getPreview($file, $x, $y, !$a, $mode);
            $response = new FileDisplayResponse($preview, Http::STATUS_OK, [
                'Content-Type' => $preview->getMimeType(),
            ]);
            $response->cacheFor(3600 * 24, false, true);

            return $response;
        } catch (\OCP\Files\NotFoundException $e) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse([], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     *
     * @PublicPage
     *
     * Get EXIF info for an image with file id
     *
     * @param string fileid
     */
    public function info(
        string $id,
        bool $basic = false,
        bool $current = false
    ): JSONResponse {
        $file = $this->getUserFile((int) $id);
        if (!$file) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Get the image info
        $info = $this->timelineQuery->getInfoById($file->getId(), $basic);

        // Get latest exif data if requested
        // Allow this ony for logged in users
        if ($current && null !== $this->userSession->getUser()) {
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
        if (!$file->isUpdateable() || !($file->getPermissions() & \OCP\Constants::PERMISSION_UPDATE)) {
            return new JSONResponse([], Http::STATUS_FORBIDDEN);
        }

        // Check for end-to-end encryption
        if (\OCA\Memories\Util::isEncryptionEnabled()) {
            return new JSONResponse(['message' => 'Cannot change encrypted file'], Http::STATUS_PRECONDITION_FAILED);
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
        $timelineWrite = new TimelineWrite($this->connection);
        $timelineWrite->processFile($file, true);

        return new JSONResponse([], Http::STATUS_OK);
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * Get a full resolution JPEG for editing from a file.
     */
    public function jpeg(string $id)
    {
        $file = $this->getUserFile((int) $id);
        if (!$file) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // check if valid image
        $mimetype = $file->getMimeType();
        if (!\in_array($mimetype, Application::IMAGE_MIMES, true)) {
            return new JSONResponse([], Http::STATUS_FORBIDDEN);
        }

        // Get the image
        $path = $file->getStorage()->getLocalFile($file->getInternalPath());
        $image = new \Imagick($path);
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(95);
        $blob = $image->getImageBlob();

        // Return the image
        $response = new Http\DataDisplayResponse($blob, Http::STATUS_OK, ['Content-Type' => $image->getImageMimeType()]);
        $response->cacheFor(3600 * 24, false, false);

        return $response;
    }
}
