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

use OCA\Memories\ClustersBackend\Backend;
use OCA\Memories\Exceptions;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;

class ClustersController extends GenericApiController
{
    /** Current backend for this instance */
    protected Backend $backend;

    /**
     * @NoAdminRequired
     *
     * Get list of clusters
     */
    public function list(string $backend): Http\Response
    {
        return Util::guardEx(function () use ($backend) {
            $this->init($backend);

            $list = $this->backend->getClusters();

            return new JSONResponse($list, Http::STATUS_OK);
        });
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * Get preview for a cluster
     */
    public function preview(string $backend, string $name): Http\Response
    {
        return Util::guardEx(function () use ($backend, $name) {
            $this->init($backend);

            // Get list of some photos in this cluster
            $photos = $this->backend->getPhotos($name, 8);

            // If no photos found then return 404
            if (0 === \count($photos)) {
                return new JSONResponse([], Http::STATUS_NOT_FOUND);
            }

            // Put the photos in the correct order
            $this->backend->sortPhotosForPreview($photos);

            // Get preview from image list
            return $this->getPreviewFromPhotoList($photos);
        });
    }

    /**
     * @NoAdminRequired
     *
     * @UseSession
     *
     * Download a cluster as a zip file
     */
    public function download(string $backend, string $name): Http\Response
    {
        return Util::guardEx(function () use ($backend, $name) {
            $this->init($backend);

            // Get list of all files in this cluster
            $photos = $this->backend->getPhotos($name);
            $fileIds = array_map(fn ($p) => $this->backend->getFileId($p), $photos);

            // Get download handle
            $filename = $this->backend->clusterName($name);
            $handle = \OCA\Memories\Controller\DownloadController::createHandle($filename, $fileIds);

            return new JSONResponse(['handle' => $handle], Http::STATUS_OK);
        });
    }

    /**
     * Initialize and check if the app is enabled.
     * Gets the root node if required.
     */
    protected function init(string $backend): void
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            throw Exceptions::NotLoggedIn();
        }

        $this->backend = Backend::get($backend);

        if (!$this->backend->isEnabled()) {
            throw Exceptions::NotEnabled($this->backend->appName());
        }
    }

    /**
     * Given a list of photo objects, return the first preview image possible.
     */
    private function getPreviewFromPhotoList(array $photos): Http\Response
    {
        // Get preview manager
        $previewManager = \OC::$server->get(\OCP\IPreview::class);

        // Try to get a preview
        $userFolder = Util::getUserFolder();
        foreach ($photos as $img) {
            // Get the file
            $files = $userFolder->getById($this->backend->getFileId($img));
            if (0 === \count($files)) {
                continue;
            }

            // Check read permission
            if (!$files[0]->isReadable()) {
                continue;
            }

            // Get preview image
            try {
                $quality = $this->backend->getPreviewQuality();
                $file = $previewManager->getPreview($files[0], $quality, $quality, false);

                [$blob, $mimetype] = $this->backend->getPreviewBlob($file, $img);

                $response = new DataDisplayResponse($blob, Http::STATUS_OK, [
                    'Content-Type' => $mimetype,
                ]);
                $response->cacheFor(3600 * 24, false, false);

                return $response;
            } catch (\Exception $e) {
                continue;
            }
        }

        throw Exceptions::NotFound('preview from photos list');
    }
}
