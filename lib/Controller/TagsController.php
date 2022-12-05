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
use OCP\AppFramework\Http\JSONResponse;

class TagsController extends ApiBase
{
    /**
     * @NoAdminRequired
     *
     * Get list of tags with counts of images
     */
    public function tags(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // Check tags enabled for this user
        if (!$this->tagsIsEnabled()) {
            return new JSONResponse(['message' => 'Tags not enabled for user'], Http::STATUS_PRECONDITION_FAILED);
        }

        // If this isn't the timeline folder then things aren't going to work
        $root = $this->getRequestRoot();
        if ($root->isEmpty()) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Run actual query
        $list = $this->timelineQuery->getTags(
            $root,
        );

        return new JSONResponse($list, Http::STATUS_OK);
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * Get preview for a tag
     */
    public function preview(string $tag): Http\Response
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // Check tags enabled for this user
        if (!$this->tagsIsEnabled()) {
            return new JSONResponse(['message' => 'Tags not enabled for user'], Http::STATUS_PRECONDITION_FAILED);
        }

        // If this isn't the timeline folder then things aren't going to work
        $root = $this->getRequestRoot();
        if ($root->isEmpty()) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Run actual query
        $list = $this->timelineQuery->getTagPreviews($tag, $root);
        if (null === $list || 0 === \count($list)) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Get preview manager
        $previewManager = \OC::$server->get(\OCP\IPreview::class);

        // Try to get a preview
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        foreach ($list as &$img) {
            // Get the file
            $files = $userFolder->getById($img['fileid']);
            if (0 === \count($files)) {
                continue;
            }

            // Check read permission
            if (!($files[0]->getPermissions() & \OCP\Constants::PERMISSION_READ)) {
                continue;
            }

            // Get preview image
            try {
                $preview = $previewManager->getPreview($files[0], 512, 512, false);
                $response = new DataDisplayResponse($preview->getContent(), Http::STATUS_OK, [
                    'Content-Type' => $preview->getMimeType(),
                ]);
                $response->cacheFor(3600 * 24, false, false);

                return $response;
            } catch (\Exception $e) {
                continue;
            }
        }

        return new JSONResponse([], Http::STATUS_NOT_FOUND);
    }
}
