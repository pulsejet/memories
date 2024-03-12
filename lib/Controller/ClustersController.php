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

use OCA\Memories\ClustersBackend;
use OCA\Memories\Exceptions;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;

class ClustersController extends GenericApiController
{
    /**
     * Current backend for this instance.
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    protected ClustersBackend\Backend $backend;

    /**
     * @NoAdminRequired
     *
     * Get list of clusters
     */
    public function list(string $backend, int $fileid = 0): Http\Response
    {
        return Util::guardEx(function () use ($backend, $fileid) {
            $this->init($backend);

            $list = $this->backend->getClusters($fileid);

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

            // Attempt to get the cover preview (-6 magic)
            $photos = $this->backend->getPhotos($name, -6);
            $isCover = !empty($photos);

            // Fall back to some random photos in the cluster
            if (!$isCover) {
                $photos = $this->backend->getPhotos($name, 8);
            }

            // If no photos found then return 404
            if (empty($photos)) {
                return new JSONResponse([
                    'message' => 'No photos found in this cluster',
                ], Http::STATUS_NOT_FOUND);
            }

            // Put the photos in the correct order
            $this->backend->sortPhotosForPreview($photos);

            // Get preview from image list
            return $this->getPreviewFromPhotoList($photos, $isCover);
        });
    }

    /**
     * @NoAdminRequired
     *
     * Set the cover image for a cluster
     */
    public function setCover(string $backend, string $name, int $fileid): Http\Response
    {
        return Util::guardEx(function () use ($backend, $name, $fileid) {
            $this->init($backend);

            $photos = $this->backend->getPhotos($name, 1, $fileid);
            if (empty($photos)) {
                throw Exceptions::NotFound('photo');
            }

            $this->backend->setCover($photos[0], true);

            return new JSONResponse([], Http::STATUS_OK);
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
        Util::getUser();

        $this->backend = ClustersBackend\Manager::get($backend);

        if (!$this->backend->isEnabled()) {
            throw Exceptions::NotEnabled($this->backend->appName());
        }
    }

    /**
     * Given a list of photo objects, return the first preview image possible.
     *
     * @param array $photos  List of photo objects
     * @param bool  $isCover Whether this is a cover preview from database
     */
    private function getPreviewFromPhotoList(array $photos, bool $isCover): Http\Response
    {
        // Get preview manager
        $previewManager = \OC::$server->get(\OCP\IPreview::class);

        // Try to get a preview
        foreach ($photos as $photo) {
            // Get preview image
            try {
                $quality = $this->backend->getPreviewQuality();

                $file = $this->fs->getUserFile($this->backend->getFileId($photo));
                $file = $previewManager->getPreview($file, $quality, $quality, false);

                [$blob, $mimetype] = $this->backend->getPreviewBlob($file, $photo);

                $response = new DataDisplayResponse($blob, Http::STATUS_OK, [
                    'Content-Type' => $mimetype,
                ]);

                if ($isCover) {
                    if ((int) $this->request->getParam('cover') === $this->backend->getCoverObjId($photo)) {
                        // Longer cache duration for cover previews that were correctly requested
                        $response->cacheFor(3600 * 7 * 24, false, false);
                    } else {
                        // This was likely requested with a random or wrong cover ID
                        $response->cacheFor(0, false, false);
                    }
                } else {
                    // If this is not a cover preview, set this as the auto-picked cover
                    $this->backend->setCover($photo);

                    // Disable caching for non-cover previews
                    $response->cacheFor(0, false, false);
                }

                return $response;
            } catch (\Exception $e) {
                continue;
            }
        }

        throw Exceptions::NotFound('preview from photos list');
    }
}
