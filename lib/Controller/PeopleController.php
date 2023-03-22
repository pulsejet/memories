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
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\FileInfo;

class PeopleController extends GenericApiController
{
    /**
     * @NoAdminRequired
     *
     * Get list of faces with counts of images
     */
    public function recognizePeople(): Http\Response
    {
        try {
            $uid = $this->getUID();
        } catch (\Exception $e) {
            return Errors::NotLoggedIn();
        }

        // Check faces enabled for this user
        if (!$this->recognizeIsEnabled()) {
            return Errors::NotEnabled('Recognize');
        }

        // If this isn't the timeline folder then things aren't going to work
        $root = $this->getRequestRoot();
        if ($root->isEmpty()) {
            return Errors::NoRequestRoot();
        }

        // Run actual query
        $list = $this->timelineQuery->getPeopleRecognize(
            $root,
            $uid
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
    public function recognizePeoplePreview(int $id): Http\Response
    {
        try {
            $uid = $this->getUID();
        } catch (\Exception $e) {
            return Errors::NotLoggedIn();
        }

        // Check faces enabled for this user
        if (!$this->recognizeIsEnabled()) {
            return Errors::NotEnabled('Recognize');
        }

        // Get folder to search for
        $root = $this->getRequestRoot();
        if ($root->isEmpty()) {
            return Errors::NoRequestRoot();
        }

        // Run actual query
        $detections = $this->timelineQuery->getPeopleRecognizePreview($root, $id, $uid);

        if (0 === \count($detections)) {
            return Errors::NotFound('detections');
        }

        return $this->getPreviewResponse($detections, 1.5);
    }

    /**
     * @NoAdminRequired
     *
     * Get list of faces with counts of images
     */
    public function facerecognitionPeople(): Http\Response
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return Errors::NotLoggedIn();
        }

        // Check if face recognition is installed and enabled for this user
        if (!$this->facerecognitionIsInstalled()) {
            return Errors::NotEnabled('Face Recognition');
        }

        // If this isn't the timeline folder then things aren't going to work
        $root = $this->getRequestRoot();
        if ($root->isEmpty()) {
            return Errors::NoRequestRoot();
        }

        // If the user has recognition disabled, just returns an empty response.
        if (!$this->facerecognitionIsEnabled()) {
            return new JSONResponse([]);
        }

        // Run actual query
        $currentModel = (int) $this->config->getAppValue('facerecognition', 'model', -1);
        $list = $this->timelineQuery->getFaceRecognitionPersons(
            $root,
            $currentModel
        );
        // Just append unnamed clusters to the end.
        $list = array_merge($list, $this->timelineQuery->getFaceRecognitionClusters(
            $root,
            $currentModel
        ));

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
    public function facerecognitionPeoplePreview(string $id): Http\Response
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return Errors::NotLoggedIn();
        }

        // Check if face recognition is installed and enabled for this user
        if (!$this->facerecognitionIsInstalled()) {
            return Errors::NotEnabled('Face Recognition');
        }

        // Get folder to search for
        $root = $this->getRequestRoot();
        if ($root->isEmpty()) {
            return Errors::NoRequestRoot();
        }

        // If the user has facerecognition disabled, just returns an empty response.
        if (!$this->facerecognitionIsEnabled()) {
            return new JSONResponse([]);
        }

        // Run actual query
        $currentModel = (int) $this->config->getAppValue('facerecognition', 'model', -1);
        $detections = $this->timelineQuery->getFaceRecognitionPreview($root, $currentModel, $id);

        if (null === $detections || 0 === \count($detections)) {
            return Errors::NotFound('detections');
        }

        return $this->getPreviewResponse($detections, 1.8);
    }

    /**
     * Get face preview image cropped with imagick.
     *
     * @param array      $detections Array of detections to search
     * @param \OCP\IUser $user       User to search for
     * @param int        $padding    Padding to add to the face in preview
     */
    private function getPreviewResponse(
        array $detections,
        float $padding
    ): Http\Response {
        // Get preview manager
        $previewManager = \OC::$server->get(\OCP\IPreview::class);

        /** @var \Imagick */
        $image = null;

        // Find the first detection that has a preview
        $userFolder = $this->rootFolder->getUserFolder($this->getUID());

        foreach ($detections as &$detection) {
            // Get the file (also checks permissions)
            $files = $userFolder->getById($detection['file_id']);
            if (0 === \count($files) || FileInfo::TYPE_FILE !== $files[0]->getType()) {
                continue;
            }

            // Check read permission
            if (!$files[0]->isReadable()) {
                continue;
            }

            // Get (hopefully cached) preview image
            try {
                $preview = $previewManager->getPreview($files[0], 2048, 2048, false);

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
            return Errors::NotFound('preview');
        }

        // Set quality and make progressive
        $image->setImageCompressionQuality(80);
        $image->setInterlaceScheme(\Imagick::INTERLACE_PLANE);

        // Crop image
        $dw = (float) $detection['width'];
        $dh = (float) $detection['height'];
        $dcx = (float) $detection['x'] + (float) $detection['width'] / 2;
        $dcy = (float) $detection['y'] + (float) $detection['height'] / 2;
        $faceDim = max($dw * $iw, $dh * $ih) * $padding;
        $image->cropImage(
            (int) $faceDim,
            (int) $faceDim,
            (int) ($dcx * $iw - $faceDim / 2),
            (int) ($dcy * $ih - $faceDim / 2),
        );
        $image->scaleImage(512, 512, true);
        $blob = $image->getImageBlob();

        // Create and send response
        $response = new DataDisplayResponse($blob, Http::STATUS_OK, [
            'Content-Type' => $image->getImageMimeType(),
        ]);
        $response->cacheFor(3600 * 24, false, false);

        return $response;
    }
}
