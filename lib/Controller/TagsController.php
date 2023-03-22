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
use OCP\AppFramework\Http\JSONResponse;

class TagsController extends GenericApiController
{
    /**
     * @NoAdminRequired
     *
     * Get list of tags with counts of images
     */
    public function tags(): Http\Response
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return Errors::NotLoggedIn();
        }

        // Check tags enabled for this user
        if (!$this->tagsIsEnabled()) {
            return Errors::NotEnabled('Tags');
        }

        // If this isn't the timeline folder then things aren't going to work
        $root = $this->getRequestRoot();
        if ($root->isEmpty()) {
            return Errors::NoRequestRoot();
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
            return Errors::NotLoggedIn();
        }

        // Check tags enabled for this user
        if (!$this->tagsIsEnabled()) {
            return Errors::NotEnabled('Tags');
        }

        // If this isn't the timeline folder then things aren't going to work
        $root = $this->getRequestRoot();
        if ($root->isEmpty()) {
            return Errors::NoRequestRoot();
        }

        // Run actual query
        $list = $this->timelineQuery->getTagPreviews($tag, $root);
        if (null === $list || 0 === \count($list)) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }
        shuffle($list);

        // Get preview from image list
        return $this->getPreviewFromImageList(array_map(function ($item) {
            return (int) $item['fileid'];
        }, $list));
    }

    /**
     * @NoAdminRequired
     *
     * Set tags for a file
     */
    public function set(int $id, array $add, array $remove): Http\Response
    {
        // Check tags enabled for this user
        if (!$this->tagsIsEnabled()) {
            return Errors::NotEnabled('Tags');
        }

        // Check the user is allowed to edit the file
        $file = $this->getUserFile($id);
        if (null === $file) {
            return Errors::NotFoundFile($id);
        }

        // Check the user is allowed to edit the file
        if (!$file->isUpdateable() || !($file->getPermissions() & \OCP\Constants::PERMISSION_UPDATE)) {
            return Errors::ForbiddenFileUpdate($file->getName());
        }

        // Get mapper from tags to objects
        $om = \OC::$server->get(\OCP\SystemTag\ISystemTagObjectMapper::class);

        // Add and remove tags
        $om->assignTags((string) $id, 'files', $add);
        $om->unassignTags((string) $id, 'files', $remove);

        return new JSONResponse([], Http::STATUS_OK);
    }
}
