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
                $this->isMonthView(),
                $this->isReverse(),
                $this->getTransformations(),
            );

            // Preload some day responses
            $this->preloadDays($list);

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
    public function day(array $dayIds): Http\Response
    {
        return Util::guardEx(function () use ($dayIds) {
            // Run actual query
            $list = $this->tq->getDay(
                $dayIds,
                $this->isRecursive(),
                $this->isArchive(),
                $this->isHidden(),
                $this->isMonthView(),
                $this->isReverse(),
                $this->getTransformations(),
            );

            return new JSONResponse($list, Http::STATUS_OK);
        });
    }

    /**
     * @NoAdminRequired
     *
     * @PublicPage
     */
    public function dayGet(string $id): Http\Response
    {
        // Split at commas and convert all parts to int
        return $this->day(array_map(static fn ($p) => (int) $p, explode(',', $id)));
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
            $this->isReverse(),
            $this->getTransformations(),
        );

        // Load details into map byref
        foreach ($details as $photo) {
            $dayId = (int) $photo['dayid'];
            if (!($drefMap[$dayId] ?? null)) {
                continue;
            }

            if (!($drefMap[$dayId]['detail'] ?? null)) {
                $drefMap[$dayId]['detail'] = [];
            }

            $drefMap[$dayId]['detail'][] = $photo;
        }
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
