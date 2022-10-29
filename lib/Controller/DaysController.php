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
use OCP\Files\FileInfo;
use OCP\Files\Folder;

class DaysController extends ApiBase
{
    /**
     * @NoAdminRequired
     *
     * @PublicPage
     */
    public function days(): JSONResponse
    {
        // Get the folder to show
        $uid = $this->getUid();

        // Get the folder to show
        $folder = null;

        try {
            $folder = $this->getRequestFolder();
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        // Params
        $recursive = null === $this->request->getParam('folder');
        $archive = null !== $this->request->getParam('archive');

        // Run actual query
        try {
            $list = $this->timelineQuery->getDays(
                $folder,
                $uid,
                $recursive,
                $archive,
                $this->getTransformations(true),
            );

            // Preload some day responses
            $this->preloadDays($list, $uid, $folder, $recursive, $archive);

            // Add subfolder info if querying non-recursively
            if (!$recursive) {
                array_unshift($list, $this->getSubfoldersEntry($folder));
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
     * @NoAdminRequired
     *
     * @PublicPage
     */
    public function day(string $id): JSONResponse
    {
        // Get user
        $uid = $this->getUid();

        // Check for wildcard
        $day_ids = [];
        if ('*' === $id) {
            $day_ids = null;
        } else {
            // Split at commas and convert all parts to int
            $day_ids = array_map(function ($part) {
                return (int) $part;
            }, explode(',', $id));
        }

        // Check if $day_ids is empty
        if (null !== $day_ids && 0 === \count($day_ids)) {
            return new JSONResponse([], Http::STATUS_OK);
        }

        // Get the folder to show
        $folder = null;

        try {
            $folder = $this->getRequestFolder();
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        // Params
        $recursive = null === $this->request->getParam('folder');
        $archive = null !== $this->request->getParam('archive');

        // Run actual query
        try {
            $list = $this->timelineQuery->getDay(
                $folder,
                $uid,
                $day_ids,
                $recursive,
                $archive,
                $this->getTransformations(false),
            );

            return new JSONResponse($list, Http::STATUS_OK);
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get subfolders entry for days response.
     */
    public function getSubfoldersEntry(Folder &$folder)
    {
        // Ugly: get the view of the folder with reflection
        // This is unfortunately the only way to get the contents of a folder
        // matching a MIME type without using SEARCH, which is deep
        $rp = new \ReflectionProperty('\OC\Files\Node\Node', 'view');
        $rp->setAccessible(true);
        $view = $rp->getValue($folder);

        // Get the subfolders
        $folders = $view->getDirectoryContent($folder->getPath(), FileInfo::MIMETYPE_FOLDER, $folder);

        // Sort by name
        usort($folders, function ($a, $b) {
            return strnatcmp($a->getName(), $b->getName());
        });

        // Process to response type
        return [
            'dayid' => \OCA\Memories\Util::$TAG_DAYID_FOLDERS,
            'count' => \count($folders),
            'detail' => array_map(function ($node) {
                return [
                    'fileid' => $node->getId(),
                    'name' => $node->getName(),
                    'isfolder' => 1,
                    'path' => $node->getPath(),
                ];
            }, $folders, []),
        ];
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

        // Filter only for one face
        if ($this->recognizeIsEnabled()) {
            $face = $this->request->getParam('face');
            if ($face) {
                $transforms[] = [$this->timelineQuery, 'transformFaceFilter', $face];
            }

            $faceRect = $this->request->getParam('facerect');
            if ($faceRect && !$aggregateOnly) {
                $transforms[] = [$this->timelineQuery, 'transformFaceRect', $face];
            }
        }

        // Filter only for one tag
        if ($this->tagsIsEnabled()) {
            if ($tagName = $this->request->getParam('tag')) {
                $transforms[] = [$this->timelineQuery, 'transformTagFilter', $tagName];
            }
        }

        // Filter for one album
        if ($this->albumsIsEnabled()) {
            if ($albumId = $this->request->getParam('album')) {
                $transforms[] = [$this->timelineQuery, 'transformAlbumFilter', $albumId];
            }
        }

        // Limit number of responses for day query
        $limit = $this->request->getParam('limit');
        if ($limit) {
            $transforms[] = [$this->timelineQuery, 'transformLimitDay', (int) $limit];
        }

        return $transforms;
    }

    /**
     * Preload a few "day" at the start of "days" response.
     *
     * @param array       $days      the days array
     * @param string      $uid       User ID or blank for public shares
     * @param null|Folder $folder    the folder to search in
     * @param bool        $recursive search in subfolders
     * @param bool        $archive   search in archive folder only
     */
    private function preloadDays(array &$days, string $uid, &$folder, bool $recursive, bool $archive)
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
                $folder,
                $uid,
                $preloadDayIds,
                $recursive,
                $archive,
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
