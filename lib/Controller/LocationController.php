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

        // Just check bound parameters instead of using them; they are used in transformation
        $minLat = $this->request->getParam('minLat');
        $maxLat = $this->request->getParam('maxLat');
        $minLng = $this->request->getParam('minLng');
        $maxLng = $this->request->getParam('maxLng');

        if (!is_numeric($minLat) || !is_numeric($maxLat) || !is_numeric($minLng) || !is_numeric($maxLng)) {
            return new JSONResponse(['message' => 'Invalid perameters'], Http::STATUS_PRECONDITION_FAILED);
        }

        // Zoom level is used to determine the grid length
        $zoomLevel = $this->request->getParam('zoom');
        if (!$zoomLevel || !is_numeric($zoomLevel)) {
            return new JSONResponse(['message' => 'Invalid zoom level'], Http::STATUS_PRECONDITION_FAILED);
        }

        // A tweakable parameter to determine the number of boxes in the map
        $clusterDensity = 2;
        $gridLength = 180.0 / (2 ** $zoomLevel * $clusterDensity);

        try {
            $clusters = $this->timelineQuery->getMapClusters(
                $gridLength,
                $root,
                $uid,
                $this->isRecursive(),
                $this->isArchive(),
                $this->getTransformations(true),
            );

            // Merge clusters that are close together
            $distanceThreshold = $gridLength / 3;
            $clusters = $this->mergeClusters($clusters, $distanceThreshold);

            return new JSONResponse($clusters);
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
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

    private function isCLose(array $cluster1, array $cluster2, float $threshold): bool
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
        $clusters[] = ['center' => $newCenter, 'count' => $cluster1Count + $cluster2Count];
        $valid[] = true;
        $valid[$index1] = $valid[$index2] = false;
    }
}
