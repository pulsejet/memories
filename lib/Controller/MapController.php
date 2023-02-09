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
     *
     * @NoCSRFRequired
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
        $clusterDensity = 2;
        $gridLen = 180.0 / (2 ** $zoomLevel * $clusterDensity);

        try {
            $clusters = $this->timelineQuery->getMapClusters($gridLen, $bounds, $root);

            // Merge clusters that are close together
            $clusters = $this->mergeClusters($clusters, $gridLen / 2);

            return new JSONResponse($clusters);
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * Get preview for a cluster
     */
    public function clusterPreview(int $id)
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // If this isn't the timeline folder then things aren't going to work
        $root = $this->getRequestRoot();
        if ($root->isEmpty()) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Run actual query
        $list = $this->timelineQuery->getMapClusterPreviews($id, $root);
        if (null === $list || 0 === \count($list)) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }
        shuffle($list);

        // Get preview from image list
        return $this->getPreviewFromImageList(array_map(static function (&$item) {
            return (int) $item['fileid'];
        }, $list), 256);
    }

    private function mergeClusters($clusters, $distanceThreshold): array
    {
        $valid = array_fill(0, \count($clusters), true);
        for ($i = 0; $i < \count($clusters); ++$i) {
            if (!$valid[$i]) {
                continue;
            }
            for ($j = 0; $j < \count($clusters); ++$j) {
                if ($i === $j) {
                    continue;
                }
                if (!$valid[$i] || !$valid[$j]) {
                    continue;
                }
                if ($this->isClose($clusters[$i], $clusters[$j], $distanceThreshold)) {
                    $this->merge($valid, $clusters, $i, $j);
                }
            }
        }

        $updatedClusters = [];
        for ($i = 0; $i < \count($clusters); ++$i) {
            if ($valid[$i]) {
                $updatedClusters[] = $clusters[$i];
            }
        }

        return $updatedClusters;
    }

    private function isClose(array $cluster1, array $cluster2, float $threshold): bool
    {
        $deltaX = (float) $cluster1['center'][0] - (float) $cluster2['center'][0];
        $deltaY = (float) $cluster1['center'][1] - (float) $cluster2['center'][1];

        return $deltaX * $deltaX + $deltaY * $deltaY < $threshold * $threshold;
    }

    private function merge(array &$valid, array &$clusters, int $index1, int $index2): void
    {
        $cluster1Count = (int) $clusters[$index1]['count'];
        $cluster1Center = $clusters[$index1]['center'];
        $cluster2Count = (int) $clusters[$index2]['count'];
        $cluster2Center = $clusters[$index2]['center'];
        $newCenter = [
            ($cluster1Count * $cluster1Center[0] + $cluster2Count * $cluster2Center[0]) / ($cluster1Count + $cluster2Count),
            ($cluster1Count * $cluster1Center[1] + $cluster2Count * $cluster2Center[1]) / ($cluster1Count + $cluster2Count),
        ];
        $clusters[] = [
            'id' => $clusters[$index1]['id'],
            'center' => $newCenter,
            'count' => $cluster1Count + $cluster2Count,
        ];
        $valid[] = true;
        $valid[$index1] = $valid[$index2] = false;
    }
}
