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
use OCA\Memories\Exceptions;
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
            $list = $this->timelineQuery->getDays(
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
            // Check for wildcard
            $dayIds = [];
            if ('*' === $id) {
                $dayIds = null;
            } else {
                // Split at commas and convert all parts to int
                $dayIds = array_map(fn ($p) => (int) $p, explode(',', $id));
            }

            // Check if $dayIds is empty
            if (null !== $dayIds && 0 === \count($dayIds)) {
                return new JSONResponse([], Http::STATUS_OK);
            }

            // Convert to actual dayIds if month view
            if ($this->isMonthView()) {
                $dayIds = $this->monthIdToDayIds((int) $dayIds[0]);
            }

            // Run actual query
            $list = $this->timelineQuery->getDay(
                $dayIds,
                $this->isRecursive(),
                $this->isArchive(),
                $this->getTransformations(),
            );

            // Force month id for dayId for month view
            if ($this->isMonthView()) {
                foreach ($list as &$photo) {
                    $photo['dayid'] = (int) $dayIds[0];
                }
            }

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
     */
    public function dayPost(): Http\Response
    {
        return Util::guardEx(function () {
            $id = $this->request->getParam('body_ids');
            if (null === $id) {
                throw Exceptions::MissingParameter('body_ids');
            }

            return $this->day($id);
        });
    }

    /**
     * Get transformations depending on the request.
     */
    private function getTransformations()
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
            $transforms[] = [$this->timelineQuery, 'transformFavoriteFilter'];
        }

        // Filter only videos
        if ($this->request->getParam('vid')) {
            $transforms[] = [$this->timelineQuery, 'transformVideoFilter'];
        }

        // Filter geological bounds
        if ($bounds = $this->request->getParam('mapbounds')) {
            $transforms[] = [$this->timelineQuery, 'transformMapBoundsFilter', $bounds];
        }

        // Limit number of responses for day query
        if ($limit = $this->request->getParam('limit')) {
            $transforms[] = [$this->timelineQuery, 'transformLimit', (int) $limit];
        }

        return $transforms;
    }

    /**
     * Preload a few "day" at the start of "days" response.
     *
     * @param array $days the days array
     */
    private function preloadDays(array &$days)
    {
        $transforms = $this->getTransformations(false);
        $preloaded = 0;
        $preloadDayIds = [];
        $preloadDays = [];
        foreach ($days as &$day) {
            if ($day['count'] <= 0) {
                continue;
            }

            $preloaded += $day['count'];
            $preloadDayIds[] = $day['dayid'];
            $preloadDays[] = &$day;

            if ($preloaded >= 50 || \count($preloadDayIds) > 5) { // should be enough
                break;
            }
        }

        if (\count($preloadDayIds) > 0) {
            $allDetails = $this->timelineQuery->getDay(
                $preloadDayIds,
                $this->isRecursive(),
                $this->isArchive(),
                $transforms,
            );

            // Group into dayid
            $detailMap = [];
            foreach ($allDetails as &$detail) {
                $detailMap[$detail['dayid']][] = &$detail;
            }
            foreach ($preloadDays as &$day) {
                $m = $detailMap[$day['dayid']];
                if (isset($m) && null !== $m && \count($m) > 0) {
                    $day['detail'] = $m;
                }
            }
        }
    }

    /**
     * Convert days response to months response.
     * The dayId is used to group the days into months.
     */
    private function daysToMonths(array $days)
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

    /** Convert list of month IDs to list of dayIds */
    private function monthIdToDayIds(int $monthId)
    {
        $dayIds = [];
        $firstDay = (int) $monthId;
        $lastDay = strtotime(date('Ymt', $firstDay * 86400)) / 86400;
        for ($i = $firstDay; $i <= $lastDay; ++$i) {
            $dayIds[] = (string) $i;
        }

        return $dayIds;
    }

    private function isRecursive()
    {
        return null === $this->request->getParam('folder') || $this->request->getParam('recursive');
    }

    private function isArchive()
    {
        return null !== $this->request->getParam('archive');
    }

    private function isMonthView()
    {
        return null !== $this->request->getParam('monthView');
    }

    private function isReverse()
    {
        return null !== $this->request->getParam('reverse');
    }
}
