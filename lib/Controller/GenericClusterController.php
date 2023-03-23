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

use OCA\Memories\Db\TimelineRoot;
use OCA\Memories\Errors;
use OCA\Memories\HttpResponseException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;

abstract class GenericClusterController extends GenericApiController
{
    protected ?TimelineRoot $root;

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * Get list of clusters
     */
    public function list(): Http\Response
    {
        return $this->guardEx(function () {
            $this->init();

            $list = $this->getClusters();

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
    public function preview(string $name): Http\Response
    {
        return $this->guardEx(function () use ($name) {
            $this->init();

            // Get list of some photos in this cluster
            $photos = $this->getPhotos($name, 8);

            // If no photos found then return 404
            if (0 === \count($photos)) {
                return new JSONResponse([], Http::STATUS_NOT_FOUND);
            }

            // Put the photos in the correct order
            $this->sortPhotosForPreview($photos);

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
    public function download(string $name): Http\Response
    {
        return $this->guardEx(function () use ($name) {
            $this->init();

            // Get list of all files in this cluster
            $photos = $this->getPhotos($name);
            $fileIds = array_map([$this, 'getFileId'], $photos);

            // Get download handle
            $filename = $this->clusterName($name);
            $handle = \OCA\Memories\Controller\DownloadController::createHandle($filename, $fileIds);

            return new JSONResponse(['handle' => $handle], Http::STATUS_OK);
        });
    }

    /**
     * A human-readable name for the app.
     * Used for error messages.
     */
    abstract protected function appName(): string;

    /**
     * Whether the app is enabled for the current user.
     */
    abstract protected function isEnabled(): bool;

    /**
     * Get the cluster list for the current user.
     */
    abstract protected function getClusters(): array;

    /**
     * Get a list of photos with any extra parameters for the given cluster
     * Used for preview generation and download.
     *
     * @param string $name  Identifier for the cluster
     * @param int    $limit Maximum number of photos to return
     */
    abstract protected function getPhotos(string $name, ?int $limit = null): array;

    /**
     * Human readable name for the cluster.
     */
    protected function clusterName(string $name)
    {
        return $name;
    }

    /**
     * Put the photo objects in priority list.
     * Works on the array in place.
     */
    protected function sortPhotosForPreview(array &$photos)
    {
        shuffle($photos);
    }

    /**
     * Quality to use for the preview file.
     */
    protected function getPreviewQuality(): int
    {
        return 512;
    }

    /**
     * Perform any post processing and get the blob from the preview file.
     *
     * @param \OCP\Files\SimpleFS\ISimpleFile $file  Preview file
     * @param array                           $photo Photo object
     *
     * @return [Blob, mimetype] of data
     */
    protected function getPreviewBlob($file, $photo): array
    {
        return [$file->getContent(), $file->getMimeType()];
    }

    /**
     * Get the file ID for a photo object.
     */
    protected function getFileId(array $photo): int
    {
        return (int) $photo['fileid'];
    }

    /**
     * Should the timeline root be queried?
     */
    protected function useTimelineRoot(): bool
    {
        return true;
    }

    /**
     * Initialize and check if the app is enabled.
     * Gets the root node if required.
     */
    protected function init(): void
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            throw new HttpResponseException(Errors::NotLoggedIn());
        }

        if (!$this->isEnabled()) {
            throw new HttpResponseException(Errors::NotEnabled($this->appName()));
        }

        $this->root = null;
        if ($this->useTimelineRoot()) {
            $this->root = $this->getRequestRoot();
            if ($this->root->isEmpty()) {
                throw new HttpResponseException(Errors::NoRequestRoot());
            }
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
        $userFolder = $this->rootFolder->getUserFolder($this->getUID());
        foreach ($photos as $img) {
            // Get the file
            $files = $userFolder->getById($this->getFileId($img));
            if (0 === \count($files)) {
                continue;
            }

            // Check read permission
            if (!$files[0]->isReadable()) {
                continue;
            }

            // Get preview image
            try {
                $quality = $this->getPreviewQuality();
                $file = $previewManager->getPreview($files[0], $quality, $quality, false);

                [$blob, $mimetype] = $this->getPreviewBlob($file, $img);

                $response = new DataDisplayResponse($blob, Http::STATUS_OK, [
                    'Content-Type' => $mimetype,
                ]);
                $response->cacheFor(3600 * 24, false, false);

                return $response;
            } catch (\Exception $e) {
                continue;
            }
        }

        return Errors::NotFound('preview from photos list');
    }
}
