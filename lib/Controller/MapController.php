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

class MapController extends ApiBase
{
    /**
     * @NoAdminRequired
     */
    public function clusters(): JSONResponse
    {
        // Get the folder to show
        $root = null;

        try {
            $root = $this->getRequestRoot();
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        // Make sure we have bounds and zoom level
        // Zoom level is used to determine the grid length
        $bounds = $this->request->getParam('bounds');
        $zoomLevel = $this->request->getParam('zoom');
        if (!$bounds || !$zoomLevel || !is_numeric($zoomLevel)) {
            return new JSONResponse(['message' => 'Invalid parameters'], Http::STATUS_PRECONDITION_FAILED);
        }

        // A tweakable parameter to determine the number of boxes in the map
        // Note: these parameters need to be changed in MapSplitMatter.vue as well
        $clusterDensity = 1;
        $gridLen = 180.0 / (2 ** $zoomLevel * $clusterDensity);

        try {
            $clusters = $this->timelineQuery->getMapClusters($gridLen, $bounds, $root);

            // Get previews for each cluster
            $clusterIds = array_map(function ($cluster) {
                return (int) $cluster['id'];
            }, $clusters);
            $previews = $this->timelineQuery->getMapClusterPreviews($clusterIds, $root);

            // Merge the responses
            $fileMap = [];
            foreach ($previews as &$preview) {
                $fileMap[$preview['mapcluster']] = $preview;
            }
            foreach ($clusters as &$cluster) {
                $cluster['preview'] = $fileMap[$cluster['id']] ?? null;
            }

            return new JSONResponse($clusters);
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
