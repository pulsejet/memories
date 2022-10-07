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
use OCP\App\IAppManager;
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
    private IAppManager $appManager;
    private TimelineQuery $timelineQuery;
    private TimelineWrite $timelineWrite;

    public function __construct(
        IRequest $request,
        IConfig $config,
        IUserSession $userSession,
        IDBConnection $connection,
        IRootFolder $rootFolder,
        IAppManager $appManager) {

        parent::__construct(Application::APPNAME, $request);

        $this->config = $config;
        $this->userSession = $userSession;
        $this->connection = $connection;
        $this->rootFolder = $rootFolder;
        $this->appManager = $appManager;
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
            $transforms[] = array($this->timelineQuery, 'transformVideoFilter');
        }

        // Filter only for one face
        if ($this->recognizeIsEnabled()) {
            $faceId = $this->request->getParam('face');
            if ($faceId) {
                $transforms[] = array($this->timelineQuery, 'transformFaceFilter', intval($faceId));
            }
        }

        // Filter only for one tag
        if ($this->tagsIsEnabled()) {
            $tagName = $this->request->getParam('tag');
            if ($tagName) {
                $transforms[] = array($this->timelineQuery, 'transformTagFilter', $tagName);
            }
        }

        // Limit number of responses for day query
        $limit = $this->request->getParam('limit');
        if ($limit) {
            $transforms[] = array($this->timelineQuery, 'transformLimitDay', intval($limit));
        }

        return $transforms;
    }

    /** Preload a few "day" at the start of "days" response */
    private function preloadDays(array &$days, Folder &$folder, bool $recursive, bool $archive) {
        $uid = $this->userSession->getUser()->getUID();
        $transforms = $this->getTransformations();
        $preloaded = 0;
        foreach ($days as &$day) {
            $day["detail"] = $this->timelineQuery->getDay(
                $folder,
                $uid,
                [$day["dayid"]],
                $recursive,
                $archive,
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
        $archive = !is_null($this->request->getParam('archive'));
        if (is_null($folder)) {
            return new JSONResponse(["message" => "Timeline folder not found"], Http::STATUS_NOT_FOUND);
        }

        // Run actual query
        $list = $this->timelineQuery->getDays(
            $folder,
            $uid,
            $recursive,
            $archive,
            $this->getTransformations(),
        );

        // Preload some day responses
        $this->preloadDays($list, $folder, $recursive, $archive);

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
    public function dayPost(): JSONResponse {
        $id = $this->request->getParam('body_ids');
        if (is_null($id)) {
            return new JSONResponse([], Http::STATUS_BAD_REQUEST);
        }
        return $this->day($id);
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

        // Check for wildcard
        $day_ids = [];
        if ($id === "*") {
            $day_ids = null;
        } else {
            // Split at commas and convert all parts to int
            $day_ids = array_map(function ($part) {
                return intval($part);
            }, explode(",", $id));
        }

        // Check if $day_ids is empty
        if (!is_null($day_ids) && count($day_ids) === 0) {
            return new JSONResponse([], Http::STATUS_OK);
        }

        // Get the folder to show
        $folder = $this->getRequestFolder();
        $recursive = is_null($this->request->getParam('folder'));
        $archive = !is_null($this->request->getParam('archive'));
        if (is_null($folder)) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Run actual query
        $list = $this->timelineQuery->getDay(
            $folder,
            $uid,
            $day_ids,
            $recursive,
            $archive,
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
     * Get list of tags with counts of images
     * @return JSONResponse
     */
    public function tags(): JSONResponse {
        $user = $this->userSession->getUser();
        if (is_null($user)) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // Check tags enabled for this user
        if (!$this->tagsIsEnabled()) {
            return new JSONResponse(["message" => "Tags not enabled for user"], Http::STATUS_PRECONDITION_FAILED);
        }

        // If this isn't the timeline folder then things aren't going to work
        $folder = $this->getRequestFolder();
        if (is_null($folder)) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Run actual query
        $list = $this->timelineQuery->getTags(
            $folder,
        );
        return new JSONResponse($list, Http::STATUS_OK);
    }

    /**
     * @NoAdminRequired
     *
     * Get list of faces with counts of images
     * @return JSONResponse
     */
    public function faces(): JSONResponse {
        $user = $this->userSession->getUser();
        if (is_null($user)) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // Check faces enabled for this user
        if (!$this->recognizeIsEnabled()) {
            return new JSONResponse(["message" => "Recognize app not enabled or not v3+"], Http::STATUS_PRECONDITION_FAILED);
        }

        // If this isn't the timeline folder then things aren't going to work
        $folder = $this->getRequestFolder();
        if (is_null($folder)) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Run actual query
        $list = $this->timelineQuery->getFaces(
            $folder,
        );
        return new JSONResponse($list, Http::STATUS_OK);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * Get preview objects for a face ID
     * @return JSONResponse
     */
    public function facePreviews(string $id): JSONResponse {
        $user = $this->userSession->getUser();
        if (is_null($user)) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // Check faces enabled for this user
        if (!$this->recognizeIsEnabled()) {
            return new JSONResponse(["message" => "Recognize app not enabled"], Http::STATUS_PRECONDITION_FAILED);
        }

        // If this isn't the timeline folder then things aren't going to work
        $folder = $this->getRequestFolder();
        if (is_null($folder)) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Run actual query
        $list = $this->timelineQuery->getFacePreviews(
            $folder, intval($id),
        );
        return new JSONResponse($list, Http::STATUS_OK);
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

        // Check if user has permissions
        if (!$file->isUpdateable()) {
            return new JSONResponse([], Http::STATUS_FORBIDDEN);
        }

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
     * Move one file to the archive folder
     * @param string fileid
     */
    public function archive(string $id): JSONResponse {
        $user = $this->userSession->getUser();
        if (is_null($user)) {
            return new JSONResponse(["message" => "Not logged in"], Http::STATUS_PRECONDITION_FAILED);
        }
        $uid = $user->getUID();
        $userFolder = $this->rootFolder->getUserFolder($uid);

        // Check for permissions and get numeric Id
        $file = $userFolder->getById(intval($id));
        if (count($file) === 0) {
            return new JSONResponse(["message" => "No such file"], Http::STATUS_NOT_FOUND);
        }
        $file = $file[0];

        // Check if user has permissions
        if (!$file->isUpdateable()) {
            return new JSONResponse(["message" => "Cannot update this file"], Http::STATUS_FORBIDDEN);
        }

        // Create archive folder in the root of the user's configured timeline
        $timelinePath = Exif::removeExtraSlash(Exif::getPhotosPath($this->config, $uid));
        $timelineFolder = $userFolder->get($timelinePath);
        if (is_null($timelineFolder) || !$timelineFolder instanceof Folder) {
            return new JSONResponse(["message" => "Cannot get timeline"], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        if (!$timelineFolder->isCreatable()) {
            return new JSONResponse(["message" => "Cannot create archive folder"], Http::STATUS_FORBIDDEN);
        }

        // Get path of current file relative to the timeline folder
        // remove timelineFolder path from start of file path
        $timelinePath = $timelineFolder->getPath(); // no trailing slash
        if (substr($file->getPath(), 0, strlen($timelinePath)) !== $timelinePath) {
            return new JSONResponse(["message" => "Files outside timeline cannot be archived"], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        $relativePath = substr($file->getPath(), strlen($timelinePath)); // has a leading slash

        // Final path of the file including the file name
        $destinationPath = '';

        // Check if we want to archive or unarchive
        $body = $this->request->getParams();
        $unarchive = isset($body['archive']) && $body['archive'] === false;

        // Get if the file is already in the archive (relativePath starts with archive)
        $archiveFolderWithLeadingSlash = '/' . \OCA\Memories\Util::$ARCHIVE_FOLDER;
        if (substr($relativePath, 0, strlen($archiveFolderWithLeadingSlash)) === $archiveFolderWithLeadingSlash) {
            // file already in archive, remove it instead
            $destinationPath = substr($relativePath, strlen($archiveFolderWithLeadingSlash));
            if (!$unarchive) {
                return new JSONResponse(["message" => "File already archived"], Http::STATUS_BAD_REQUEST);
            }
        } else {
            // file not in archive, put it in there
            $destinationPath = Exif::removeExtraSlash(\OCA\Memories\Util::$ARCHIVE_FOLDER . $relativePath);
            if ($unarchive) {
                return new JSONResponse(["message" => "File not archived"], Http::STATUS_BAD_REQUEST);
            }
        }

        // Remove the filename
        $destinationFolders = explode('/', $destinationPath);
        array_pop($destinationFolders);

        // Create folder tree
        $folder = $timelineFolder;
        foreach ($destinationFolders as $folderName) {
            if ($folderName === '') {
                continue;
            }
            try {
                $existingFolder = $folder->get($folderName . '/');
                if (!$existingFolder instanceof Folder) {
                    throw new \OCP\Files\NotFoundException('Not a folder');
                }
                $folder = $existingFolder;
            } catch (\OCP\Files\NotFoundException $e) {
                try {
                    $folder = $folder->newFolder($folderName);
                } catch (\OCP\Files\NotPermittedException $e) {
                    return new JSONResponse(["message" => "Failed to create folder"], Http::STATUS_FORBIDDEN);
                }
            }
        }

        // Move file to archive folder
        try {
            $file->move($folder->getPath() . '/' . $file->getName());
        } catch (\OCP\Files\NotPermittedException $e) {
            return new JSONResponse(["message" => "Failed to move file"], Http::STATUS_FORBIDDEN);
        } catch (\OCP\Files\NotFoundException $e) {
            return new JSONResponse(["message" => "File not found"], Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (\OCP\Files\InvalidPathException $e) {
            return new JSONResponse(["message" => "Invalid path"], Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (\OCP\Lock\LockedException $e) {
            return new JSONResponse(["message" => "File is locked"], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse([], Http::STATUS_OK);
    }

    /**
     * Check if tags is enabled for this user
     */
    private function tagsIsEnabled(): bool {
        return $this->appManager->isEnabledForUser('systemtags');
    }

    /**
     * Check if recognize is enabled for this user
     */
    private function recognizeIsEnabled(): bool {
        if (!$this->appManager->isEnabledForUser('recognize')) {
            return false;
        }

        $v = $this->appManager->getAppInfo('recognize')["version"];
        return version_compare($v, "3.0.0-alpha", ">=");
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