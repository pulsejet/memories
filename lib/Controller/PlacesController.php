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

class PlacesController extends GenericApiController
{
    /**
     * @NoAdminRequired
     *
     * Get list of places with counts of images
     */
    public function places(): Http\Response
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return Errors::NotLoggedIn();
        }

        // Check tags enabled for this user
        if (!$this->placesIsEnabled()) {
            return Errors::NotEnabled('places');
        }

        // If this isn't the timeline folder then things aren't going to work
        $root = $this->getRequestRoot();
        if ($root->isEmpty()) {
            return Errors::NoRequestRoot();
        }

        // Run actual query
        $list = $this->timelineQuery->getPlaces($root);

        return new JSONResponse($list, Http::STATUS_OK);
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * Get preview for a location
     */
    public function preview(int $id): Http\Response
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return Errors::NotLoggedIn();
        }

        // Check tags enabled for this user
        if (!$this->placesIsEnabled()) {
            return Errors::NotEnabled('places');
        }

        // If this isn't the timeline folder then things aren't going to work
        $root = $this->getRequestRoot();
        if ($root->isEmpty()) {
            return Errors::NoRequestRoot();
        }

        // Run actual query
        $list = $this->timelineQuery->getPlacePreviews($id, $root);
        if (null === $list || 0 === \count($list)) {
            return Errors::NotFound('previews');
        }
        shuffle($list);

        // Get preview from image list
        return $this->getPreviewFromImageList(array_map(function ($item) {
            return (int) $item['fileid'];
        }, $list));
    }
}
