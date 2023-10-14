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

use OCA\Memories\ClustersBackend;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

class DaysController extends GenericApiController
{
    /**
     * @NoAdminRequired
     *
     * @PublicPage
     */
    public function days(): Http\Response
    {
        return Util::guardEx(function () {
            $list = $this->tq->getDays(
                $this->isRecursive(),
                $this->isArchive(),
                $this->getTransformations(),
            );

            if ($this->isMonthView()) {
                // Group days together into months
                $list = $this->daysToMonths($list);
            } else {
                // Preload some day responses
                $this->preloadDays($list);
            }

            // Reverse response if requested. Folders still stay at top.
            if ($this->isReverse()) {
                $list = array_reverse($list);
            }

            return new JSONResponse($list, Http::STATUS_OK);
        });
    }

    /**
     * @NoAdminRequired
     *
     * @PublicPage
     */
    public function day(string $id): Http\Response
    {
        return Util::guardEx(function () use ($id) {
            // Split at commas and convert all parts to int
            $dayIds = array_map(static fn ($p) => (int) $p, explode(',', $id));

            // Run actual query
            $list = $this->tq->getDay(
                $dayIds,
                $this->isRecursive(),
                $this->isArchive(),
                $this->isHidden(),
                $this->isMonthView(),
                $this->getTransformations(),
            );

            // Reverse response if requested.
            if ($this->isReverse()) {
                $list = array_reverse($list);
            }

            return new JSONResponse($list, Http::STATUS_OK);
        });
    }

    /**
     * @NoAdminRequired
     *
     * @PublicPage
     *
     * @param int[] $dayIds
     */
    public function dayPost(array $dayIds): Http\Response
    {
        return $this->day(implode(',', $dayIds));
    }

    /**
     * Get transformations depending on the request.
     */
    private function getTransformations(): array
    {
        $transforms = [];

        // Add clustering transforms
        $clusterTs = ClustersBackend\Manager::getTransforms($this->request);
        $transforms = array_merge($transforms, $clusterTs);

        // Other transforms not allowed for public shares
        if (!Util::isLoggedIn()) {
            return $transforms;
        }

        // Filter only favorites
        if ($this->request->getParam('fav')) {
            $transforms[] = [$this->tq, 'transformFavoriteFilter'];
        }

        // Filter only videos
        if ($this->request->getParam('vid')) {
            $transforms[] = [$this->tq, 'transformVideoFilter'];
        }

        // Filter geological bounds
        if ($bounds = $this->request->getParam('mapbounds')) {
            $transforms[] = [$this->tq, 'transformMapBoundsFilter', $bounds];
        }

        // Limit number of responses for day query
        if ($limit = $this->request->getParam('limit')) {
            $transforms[] = [$this->tq, 'transformLimit', (int) $limit];
        }

        // Add extra fields for native callers
        if (Util::callerIsNative()) {
            $transforms[] = [$this->tq, 'transformNativeQuery'];
        }

        return $transforms;
    }

    /**
     * Preload a few "day" at the start of "days" response.
     *
     * @param array $days the days array (modified in place)
     */
    private function preloadDays(array &$days): void
    {
        // Do not preload anything for native clients.
        // Since the contents of preloads are trusted, clients will not load locals.
        if (Util::callerIsNative() || $this->noPreload()) {
            return;
        }

        // Construct map of dayid-day
        $totalCount = 0;
        $drefMap = [];
        foreach ($days as &$day) {
            if ($count = (int) $day['count']) {
                $totalCount += max($count, 10); // max 5 days
            }

            $dayId = (int) $day['dayid'];
            $drefMap[$dayId] = &$day;

            if ($totalCount >= 50) { // should be enough
                break;
            }
        }

        if (!$totalCount) {
            return;
        }

        // Preload photos for these days
        $details = $this->tq->getDay(
            array_keys($drefMap),
            $this->isRecursive(),
            $this->isArchive(),
            $this->isHidden(),
            $this->isMonthView(),
            $this->getTransformations(),
        );

        // Load details into map byref
        foreach ($details as $photo) {
            $dayId = (int) $photo['dayid'];
            if (!\array_key_exists($dayId, $drefMap)) {
                continue;
            }

            if (!\array_key_exists('detail', $drefMap[$dayId])) {
                $drefMap[$dayId]['detail'] = [];
            }

            $drefMap[$dayId]['detail'][] = $photo;
        }
    }

    /**
     * Convert days response to months response.
     * The dayId is used to group the days into months.
     */
    private function daysToMonths(array $days): array
    {
        $months = [];
        foreach ($days as $day) {
            $dayId = $day['dayid'];
            $time = $dayId * 86400;
            $monthid = strtotime(date('Ym', $time).'01') / 86400;

            if (empty($months) || $months[\count($months) - 1]['dayid'] !== $monthid) {
                $months[] = [
                    'dayid' => $monthid,
                    'count' => 0,
                ];
            }

            $months[\count($months) - 1]['count'] += $day['count'];
        }

        return $months;
    }

    /**
     * Convert list of month IDs to list of dayIds.
     *
     * @return int[] The list of dayIds
     */
    private function monthIdToDayIds(int $monthId): array
    {
        return range($monthId, (int) (strtotime(date('Ymt', $monthId * 86400)) / 86400));
    }

    private function isRecursive(): bool
    {
        return null === $this->request->getParam('folder') || $this->request->getParam('recursive');
    }

    private function isArchive(): bool
    {
        return null !== $this->request->getParam('archive');
    }

    private function isHidden(): bool
    {
        return null !== $this->request->getParam('hidden');
    }

    private function noPreload(): bool
    {
        return null !== $this->request->getParam('nopreload');
    }

    private function isMonthView(): bool
    {
        return null !== $this->request->getParam('monthView');
    }

    private function isReverse(): bool
    {
        return null !== $this->request->getParam('reverse');
    }
}
