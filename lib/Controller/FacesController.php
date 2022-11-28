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
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\FileInfo;

class FacesController extends ApiBase
{
    /**
     * @NoAdminRequired
     *
     * Get list of faces with counts of images
     */
    public function faces(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // Check faces enabled for this user
        if (!$this->recognizeIsEnabled()) {
            return new JSONResponse(['message' => 'Recognize app not enabled or not v3+'], Http::STATUS_PRECONDITION_FAILED);
        }

        // If this isn't the timeline folder then things aren't going to work
        $root = $this->getRequestRoot();
        if ($root->isEmpty()) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Run actual query
        $list = $this->timelineQuery->getFaces(
            $root,
        );

        return new JSONResponse($list, Http::STATUS_OK);
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * Get face preview image cropped with imagick
     *
     * @return DataResponse
     */
    public function preview(string $id): Http\Response
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new DataResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // Check faces enabled for this user
        if (!$this->recognizeIsEnabled()) {
            return new DataResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // Get folder to search for
        $root = $this->getRequestRoot();
        if ($root->isEmpty()) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Run actual query
        $detections = $this->timelineQuery->getFacePreviewDetection($root, (int) $id);
        if (null === $detections || 0 === \count($detections)) {
            return new DataResponse([], Http::STATUS_NOT_FOUND);
        }

        // Find the first detection that has a preview
        /** @var \Imagick */
        $image = null;
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        foreach ($detections as &$detection) {
            // Get the file (also checks permissions)
            $files = $userFolder->getById($detection['file_id']);
            if (0 === \count($files) || FileInfo::TYPE_FILE !== $files[0]->getType()) {
                continue;
            }

            // Check read permission
            if (!($files[0]->getPermissions() & \OCP\Constants::PERMISSION_READ)) {
                continue;
            }

            // Get (hopefully cached) preview image
            try {
                $preview = $this->previewManager->getPreview($files[0], 2048, 2048, false);

                $image = new \Imagick();
                if (!$image->readImageBlob($preview->getContent())) {
                    throw new \Exception('Failed to read image blob');
                }
                $iw = $image->getImageWidth();
                $ih = $image->getImageHeight();

                if ($iw <= 0 || $ih <= 0) {
                    $image = null;

                    throw new \Exception('Invalid image size');
                }
            } catch (\Exception $e) {
                continue;
            }

            // Got the preview
            break;
        }

        // Make sure the preview is valid
        if (null === $image) {
            return new DataResponse([], Http::STATUS_NOT_FOUND);
        }

        // Crop image
        $dw = (float) $detection['width'];
        $dh = (float) $detection['height'];
        $dcx = (float) $detection['x'] + (float) $detection['width'] / 2;
        $dcy = (float) $detection['y'] + (float) $detection['height'] / 2;
        $faceDim = max($dw * $iw, $dh * $ih) * 1.5;
        $image->cropImage(
            (int) $faceDim,
            (int) $faceDim,
            (int) ($dcx * $iw - $faceDim / 2),
            (int) ($dcy * $ih - $faceDim / 2),
        );
        $image->scaleImage(256, 256, true);
        $blob = $image->getImageBlob();

        // Create and send response
        $response = new DataDisplayResponse($blob, Http::STATUS_OK, [
            'Content-Type' => $image->getImageMimeType(),
        ]);
        $response->cacheFor(3600 * 24, false, false);

        return $response;
    }
}
