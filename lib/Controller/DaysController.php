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

use OCA\Memories\Db\TimelineRoot;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

class DaysController extends ApiBase
{
    use FoldersTrait;

    /**
     * @NoAdminRequired
     *
     * @PublicPage
     */
    public function days(): JSONResponse
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

        // Run actual query
        try {
            $list = $this->timelineQuery->getDays(
                $root,
                $uid,
                $this->isRecursive(),
                $this->isArchive(),
                $this->getTransformations(true),
            );

            if ($this->isMonthView()) {
                // Group days together into months
                $list = $this->timelineQuery->daysToMonths($list);
            } else {
                // Preload some day responses
                $this->preloadDays($list, $uid, $root);
            }

            // Reverse response if requested. Folders still stay at top.
            if ($this->isReverse()) {
                $list = array_reverse($list);
            }

            // Add subfolder info if querying non-recursively
            if (!$this->isRecursive()) {
                array_unshift($list, $this->getSubfoldersEntry($root->getFolder($root->getOneId())));
            }

            return new JSONResponse($list, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     *
     * @PublicPage
     */
    public function day(string $id): JSONResponse
    {
        // Get user
        $uid = $this->getUID();

        // Check for wildcard
        $dayIds = [];
        if ('*' === $id) {
            $dayIds = null;
        } else {
            // Split at commas and convert all parts to int
            $dayIds = array_map(function ($part) {
                return (int) $part;
            }, explode(',', $id));
        }

        // Check if $dayIds is empty
        if (null !== $dayIds && 0 === \count($dayIds)) {
            return new JSONResponse([], Http::STATUS_OK);
        }

        // Get the folder to show
        $root = null;

        try {
            $root = $this->getRequestRoot();
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        // Convert to actual dayIds if month view
        if ($this->isMonthView()) {
            $dayIds = $this->timelineQuery->monthIdToDayIds((int) $dayIds[0]);
        }

        // Run actual query
        try {
            $list = $this->timelineQuery->getDay(
                $root,
                $uid,
                $dayIds,
                $this->isRecursive(),
                $this->isArchive(),
                $this->getTransformations(false),
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
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     *
     * @PublicPage
     */
    public function dayPost(): JSONResponse
    {
        $id = $this->request->getParam('body_ids');
        if (null === $id) {
            return new JSONResponse([], Http::STATUS_BAD_REQUEST);
        }

        return $this->day($id);
    }

    /**
     * Get transformations depending on the request.
     *
     * @param bool $aggregateOnly Only apply transformations for aggregation (days call)
     */
    private function getTransformations(bool $aggregateOnly)
    {
        $transforms = [];

        // Add extra information, basename and mimetype
        if (!$aggregateOnly && ($fields = $this->request->getParam('fields'))) {
            $fields = explode(',', $fields);
            $transforms[] = [$this->timelineQuery, 'transformExtraFields', $fields];
        }

        // Filter for one album
        if ($this->albumsIsEnabled()) {
            if ($albumId = $this->request->getParam('album')) {
                $transforms[] = [$this->timelineQuery, 'transformAlbumFilter', $albumId];
            }
        }

        // Other transforms not allowed for public shares
        if (null === $this->userSession->getUser()) {
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

        // Filter only for one face on Recognize
        if (($recognize = $this->request->getParam('recognize')) && $this->recognizeIsEnabled()) {
            $transforms[] = [$this->timelineQuery, 'transformPeopleRecognitionFilter', $recognize];

            $faceRect = $this->request->getParam('facerect');
            if ($faceRect && !$aggregateOnly) {
                $transforms[] = [$this->timelineQuery, 'transformPeopleRecognizeRect', $recognize];
            }
        }

        // Filter only for one face on Face Recognition
        if (($face = $this->request->getParam('facerecognition')) && $this->facerecognitionIsEnabled()) {
            $currentModel = (int) $this->config->getAppValue('facerecognition', 'model', -1);
            $transforms[] = [$this->timelineQuery, 'transformPeopleFaceRecognitionFilter', $currentModel, $face];

            $faceRect = $this->request->getParam('facerect');
            if ($faceRect && !$aggregateOnly) {
                $transforms[] = [$this->timelineQuery, 'transformPeopleFaceRecognitionRect', $face];
            }
        }

        // Filter only for one tag
        if ($this->tagsIsEnabled()) {
            if ($tagName = $this->request->getParam('tag')) {
                $transforms[] = [$this->timelineQuery, 'transformTagFilter', $tagName];
            }
        }

        // Limit number of responses for day query
        $limit = $this->request->getParam('limit');
        if ($limit) {
            $transforms[] = [$this->timelineQuery, 'transformLimitDay', (int) $limit];
        }

        // Filter geological bounds
        $minLat = $this->request->getParam('minLat');
        $maxLat = $this->request->getParam('maxLat');
        $minLng = $this->request->getParam('minLng');
        $maxLng = $this->request->getParam('maxLng');
        if ($minLat && $maxLat && $minLng && $maxLng) {
            $transforms[] = [$this->timelineQuery, 'transformBoundFilter', $minLat, $maxLat, $minLng, $maxLng];
        }

        return $transforms;
    }

    /**
     * Preload a few "day" at the start of "days" response.
     *
     * @param array        $days the days array
     * @param string       $uid  User ID or blank for public shares
     * @param TimelineRoot $root the root folder
     */
    private function preloadDays(array &$days, string $uid, TimelineRoot &$root)
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
                $root,
                $uid,
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
}
