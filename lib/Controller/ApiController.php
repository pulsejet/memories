<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @author John Molakvoæ <skjnldsv@protonmail.com>
 *
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
 *
 */

namespace OCA\Memories\Controller;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Db\TimelineWrite;
use OCA\Memories\Exif;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Files\FileInfo;
use OCP\Files\Folder;

class ApiController extends Controller {
    private IConfig $config;
    private IUserSession $userSession;
    private IDBConnection $connection;
    private IRootFolder $rootFolder;
    private TimelineQuery $timelineQuery;
    private TimelineWrite $timelineWrite;

    public function __construct(
        IRequest $request,
        IConfig $config,
        IUserSession $userSession,
        IDBConnection $connection,
        IRootFolder $rootFolder) {

        parent::__construct(Application::APPNAME, $request);

        $this->config = $config;
        $this->userSession = $userSession;
        $this->connection = $connection;
        $this->rootFolder = $rootFolder;
        $this->timelineQuery = new TimelineQuery($this->connection);
        $this->timelineWrite = new TimelineWrite($connection);
    }

    /**
     * Get transformations depending on the request
     */
    private function getTransformations() {
        $transforms = array();

        // Filter only favorites
        if ($this->request->getParam('fav')) {
            $transforms[] = array($this->timelineQuery, 'transformFavoriteFilter');
        }

        // Filter only videos
        if ($this->request->getParam('vid')) {
            $transforms[] = array($this->timelineQuery, 'videoFilter');
        }

        return $transforms;
    }

    /** Preload a few "day" at the start of "days" response */
    private function preloadDays(array &$days, Folder &$folder, bool $recursive) {
        $uid = $this->userSession->getUser()->getUID();
        $transforms = $this->getTransformations();
        $preloaded = 0;
        foreach ($days as &$day) {
            $day["detail"] = $this->timelineQuery->getDay(
                $folder,
                $uid,
                $day["dayid"],
                $recursive,
                $transforms,
            );
            $day["count"] = count($day["detail"]); // make sure count is accurate
            $preloaded += $day["count"];

            if ($preloaded >= 50) { // should be enough
                break;
            }
        }
    }

    /** Get the Folder object relevant to the request */
    private function getRequestFolder() {
        $uid = $this->userSession->getUser()->getUID();
        try {
            $folder = null;
            $folderPath = $this->request->getParam('folder');
            $userFolder = $this->rootFolder->getUserFolder($uid);

            if (!is_null($folderPath)) {
                $folder = $userFolder->get($folderPath);
            } else {
                $configPath = Exif::removeExtraSlash(Exif::getPhotosPath($this->config, $uid));
                $folder = $userFolder->get($configPath);
            }

            if (!$folder instanceof Folder) {
                throw new \Exception("Folder not found");
            }
        } catch (\Exception $e) {
            return null;
        }
        return $folder;
    }

    /**
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function days(): JSONResponse {
        $user = $this->userSession->getUser();
        if (is_null($user)) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }
        $uid = $user->getUID();

        // Get the folder to show
        $folder = $this->getRequestFolder();
        $recursive = is_null($this->request->getParam('folder'));
        if (is_null($folder)) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Run actual query
        $list = $this->timelineQuery->getDays(
            $folder,
            $uid,
            $recursive,
            $this->getTransformations(),
        );

        // Preload some day responses
        $this->preloadDays($list, $folder, $recursive);

        // Add subfolder info if querying non-recursively
        if (!$recursive) {
            array_unshift($list, $this->getSubfoldersEntry($folder));
        }

        return new JSONResponse($list, Http::STATUS_OK);
    }

    /**
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function day(string $id): JSONResponse {
        $user = $this->userSession->getUser();
        if (is_null($user)) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }
        $uid = $user->getUID();

        // Get the folder to show
        $folder = $this->getRequestFolder();
        $recursive = is_null($this->request->getParam('folder'));
        if (is_null($folder)) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Run actual query
        $list = $this->timelineQuery->getDay(
            $folder,
            $uid,
            intval($id),
            $recursive,
            $this->getTransformations(),
        );
        return new JSONResponse($list, Http::STATUS_OK);
    }

    /**
     * Get subfolders entry for days response
     */
    public function getSubfoldersEntry(Folder &$folder) {
        // Ugly: get the view of the folder with reflection
        // This is unfortunately the only way to get the contents of a folder
        // matching a MIME type without using SEARCH, which is deep
        $rp = new \ReflectionProperty('\OC\Files\Node\Node', 'view');
        $rp->setAccessible(true);
        $view = $rp->getValue($folder);

        // Get the subfolders
        $folders = $view->getDirectoryContent($folder->getPath(), FileInfo::MIMETYPE_FOLDER, $folder);

        // Sort by name
        usort($folders, function($a, $b) {
            return strnatcmp($a->getName(), $b->getName());
        });

        // Process to response type
        return [
            "dayid" => \OCA\Memories\Util::$TAG_DAYID_FOLDERS,
            "count" => count($folders),
            "detail" => array_map(function ($node) {
                return [
                    "fileid" => $node->getId(),
                    "name" => $node->getName(),
                    "isfolder" => 1,
                    "path" => $node->getPath(),
                ];
            }, $folders, []),
        ];
    }

    /**
     * @NoAdminRequired
     *
     * Get image info for one file
     * @param string fileid
     */
    public function imageInfo(string $id): JSONResponse {
        $user = $this->userSession->getUser();
        if (is_null($user)) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());

        // Check for permissions and get numeric Id
        $file = $userFolder->getById(intval($id));
        if (count($file) === 0) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }
        $file = $file[0];

        // Get the image info
        $info = $this->timelineQuery->getInfoById($file->getId());

        return new JSONResponse($info, Http::STATUS_OK);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * Change exif data for one file
     * @param string fileid
     */
    public function imageEdit(string $id): JSONResponse {
        $user = $this->userSession->getUser();
        if (is_null($user)) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());

        // Check for permissions and get numeric Id
        $file = $userFolder->getById(intval($id));
        if (count($file) === 0) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }
        $file = $file[0];

        // TODO: check permissions

        // Get new date from body
        $body = $this->request->getParams();
        if (!isset($body['date'])) {
            return new JSONResponse(["message" => "Missing date"], Http::STATUS_BAD_REQUEST);
        }

        // Make sure the date is valid
        try {
            Exif::parseExifDate($body['date']);
        } catch (\Exception $e) {
            return new JSONResponse(["message" => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        // Update date
        try {
            $res = Exif::updateExifDate($file, $body['date']);
            if ($res === false) {
                return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
            }
        } catch (\Exception $e) {
            return new JSONResponse(["message" => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // Reprocess the file
        $this->timelineWrite->processFile($file, true);

        return $this->imageInfo($id);
    }

    /**
     * @NoAdminRequired
     *
     * update preferences (user setting)
     *
     * @param string key the identifier to change
     * @param string value the value to set
     *
     * @return JSONResponse an empty JSONResponse with respective http status code
     */
    public function setUserConfig(string $key, string $value): JSONResponse {
        $user = $this->userSession->getUser();
        if (is_null($user)) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        $userId = $user->getUid();
        $this->config->setUserValue($userId, Application::APPNAME, $key, $value);
        return new JSONResponse([], Http::STATUS_OK);
    }
}