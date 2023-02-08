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
use OCP\AppFramework\Http\JSONResponse;

class LocationController extends ApiBase
{
    /**
     * @NoAdminRequired
     *
     * @PublicPage
     *
     * @NoCSRFRequired
     */
    public function clusters(): JSONResponse
    {
        // Get the folder to show
        try {
            $uid = $this->getUID();
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_PRECONDITION_FAILED);
        }

        // Get the folder to show
        $root = null;

        try {
            $root = $this->getRequestRoot();
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        // Just check bound parameters but not use them; they are used in transformation
        $minLat = $this->request->getParam('minLat');
        $maxLat = $this->request->getParam('maxLat');
        $minLng = $this->request->getParam('minLng');
        $maxLng = $this->request->getParam('maxLng');

        if (!$minLat || !$maxLat || !$minLng || !$maxLng) {
            return new JSONResponse(['message' => 'Parameters missing'], Http::STATUS_PRECONDITION_FAILED);
        }

        // Zoom level is used to determine the size of boxes
        $zoomLevel = $this->request->getParam('zoom');
        if (!$zoomLevel || !is_numeric($zoomLevel)) {
            return new JSONResponse(['message' => 'Invalid zoom level'], Http::STATUS_PRECONDITION_FAILED);
        }

        // A tweakable parameter to determine the number of boxes in the map
        $clusterDensity = 3;
        $boxSize = 180.0 / (2 ** $zoomLevel * $clusterDensity);

        try {
            $clusters = $this->timelineQuery->getMapClusters(
                $boxSize,
                $root,
                $uid,
                $this->isRecursive(),
                $this->isArchive(),
                $this->getTransformations(true),
            );

            return new JSONResponse($clusters);
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
