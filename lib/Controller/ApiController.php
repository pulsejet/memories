<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2020 John Molakvoæ <skjnldsv@protonmail.com>
 * @author John Molakvoæ <skjnldsv@protonmail.com>
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

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Db\TimelineWrite;
use OCA\Memories\Exif;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IPreview;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends Controller
{
    private IConfig $config;
    private IUserSession $userSession;
    private IDBConnection $connection;
    private IRootFolder $rootFolder;
    private IAppManager $appManager;
    private TimelineQuery $timelineQuery;
    private TimelineWrite $timelineWrite;
    private IPreview $preview;

    public function __construct(
        IRequest $request,
        IConfig $config,
        IUserSession $userSession,
        IDBConnection $connection,
        IRootFolder $rootFolder,
        IAppManager $appManager,
        IPreview $preview
    ) {
        parent::__construct(Application::APPNAME, $request);

        $this->config = $config;
        $this->userSession = $userSession;
        $this->connection = $connection;
        $this->rootFolder = $rootFolder;
        $this->appManager = $appManager;
        $this->previewManager = $preview;
        $this->timelineQuery = new TimelineQuery($this->connection);
        $this->timelineWrite = new TimelineWrite($connection, $preview);
    }

    /**
     * @NoAdminRequired
     */
    public function days(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }
        $uid = $user->getUID();

        // Get the folder to show
        $folder = $this->getRequestFolder();
        $recursive = null === $this->request->getParam('folder');
        $archive = null !== $this->request->getParam('archive');
        if (null === $folder) {
            return new JSONResponse(['message' => 'Folder not found'], Http::STATUS_NOT_FOUND);
        }

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
            $this->preloadDays($list, $folder, $recursive, $archive);

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
     */
    public function day(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }
        $uid = $user->getUID();

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
        $folder = $this->getRequestFolder();
        $recursive = null === $this->request->getParam('folder');
        $archive = null !== $this->request->getParam('archive');
        if (null === $folder) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

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
     * @NoAdminRequired
     *
     * Get list of tags with counts of images
     */
    public function tags(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // Check tags enabled for this user
        if (!$this->tagsIsEnabled()) {
            return new JSONResponse(['message' => 'Tags not enabled for user'], Http::STATUS_PRECONDITION_FAILED);
        }

        // If this isn't the timeline folder then things aren't going to work
        $folder = $this->getRequestFolder();
        if (null === $folder) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Run actual query
        $list = $this->timelineQuery->getTags(
            $folder,
        );

        // Preload all tag previews
        $this->timelineQuery->getTagPreviews($list, $folder);

        return new JSONResponse($list, Http::STATUS_OK);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * Get list of albums with counts of images
     */
    public function albums(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // Check tags enabled for this user
        if (!$this->albumsIsEnabled()) {
            return new JSONResponse(['message' => 'Albums not enabled for user'], Http::STATUS_PRECONDITION_FAILED);
        }

        // Run actual query
        $list = $this->timelineQuery->getAlbums($user->getUID());

        return new JSONResponse($list, Http::STATUS_OK);
    }

    /**
     * @NoAdminRequired
     *
     * Get list of faces with counts of images
     */
    public function faces(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // Check faces enabled for this user
        if (!$this->recognizeIsEnabled()) {
            return new JSONResponse(['message' => 'Recognize app not enabled or not v3+'], Http::STATUS_PRECONDITION_FAILED);
        }

        // If this isn't the timeline folder then things aren't going to work
        $folder = $this->getRequestFolder();
        if (null === $folder) {
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
     *
     * @NoCSRFRequired
     *
     * Get face preview image cropped with imagick
     *
     * @return DataResponse
     */
    public function facePreview(string $id): Http\Response
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new DataResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // Check faces enabled for this user
        if (!$this->recognizeIsEnabled()) {
            return new DataResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // Get folder to search for
        $folder = $this->getRequestFolder();
        if (null === $folder) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Run actual query
        $detections = $this->timelineQuery->getFacePreviewDetection($folder, (int) $id);
        if (null === $detections || 0 === \count($detections)) {
            return new DataResponse([], Http::STATUS_NOT_FOUND);
        }

        // Find the first detection that has a preview
        $preview = null;
        foreach ($detections as &$detection) {
            // Get the file (also checks permissions)
            $files = $folder->getById($detection['file_id']);
            if (0 === \count($files) || FileInfo::TYPE_FILE !== $files[0]->getType()) {
                continue;
            }

            // Get (hopefully cached) preview image
            try {
                $preview = $this->previewManager->getPreview($files[0], 2048, 2048, false);
            } catch (\Exception $e) {
                continue;
            }

            // Got the preview
            break;
        }

        // Make sure the preview is valid
        if (null === $preview) {
            return new DataResponse([], Http::STATUS_NOT_FOUND);
        }

        // Crop image
        $image = new \Imagick();
        $image->readImageBlob($preview->getContent());
        $iw = $image->getImageWidth();
        $ih = $image->getImageHeight();
        $dw = (float) $detection['width'];
        $dh = (float) $detection['height'];
        $dcx = (float) $detection['x'] + (float) $detection['width'] / 2;
        $dcy = (float) $detection['y'] + (float) $detection['height'] / 2;
        $faceDim = max($dw * $iw, $dh * $ih) * 1.5;
        $image->cropImage(
            (int) $faceDim,
            (int) $faceDim,
            (int) ($dcx * $iw - $faceDim / 2),
            (int) ($dcy * $ih - $faceDim / 2),
        );
        $image->scaleImage(256, 256, true);
        $blob = $image->getImageBlob();

        // Create and send response
        $response = new DataDisplayResponse($blob, Http::STATUS_OK, [
            'Content-Type' => $image->getImageMimeType(),
        ]);
        $response->cacheFor(3600 * 24, false, false);

        return $response;
    }

    /**
     * @NoAdminRequired
     *
     * Get image info for one file
     *
     * @param string fileid
     */
    public function imageInfo(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());

        // Check for permissions and get numeric Id
        $file = $userFolder->getById((int) $id);
        if (0 === \count($file)) {
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
     *
     * @param string fileid
     */
    public function imageEdit(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());

        // Check for permissions and get numeric Id
        $file = $userFolder->getById((int) $id);
        if (0 === \count($file)) {
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
            return new JSONResponse(['message' => 'Missing date'], Http::STATUS_BAD_REQUEST);
        }

        // Make sure the date is valid
        try {
            Exif::parseExifDate($body['date']);
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        // Update date
        try {
            $res = Exif::updateExifDate($file, $body['date']);
            if (false === $res) {
                return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
            }
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // Reprocess the file
        $this->timelineWrite->processFile($file, true);

        return $this->imageInfo($id);
    }

    /**
     * @NoAdminRequired
     *
     * Move one file to the archive folder
     *
     * @param string fileid
     */
    public function archive(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse(['message' => 'Not logged in'], Http::STATUS_PRECONDITION_FAILED);
        }
        $uid = $user->getUID();
        $userFolder = $this->rootFolder->getUserFolder($uid);

        // Check for permissions and get numeric Id
        $file = $userFolder->getById((int) $id);
        if (0 === \count($file)) {
            return new JSONResponse(['message' => 'No such file'], Http::STATUS_NOT_FOUND);
        }
        $file = $file[0];

        // Check if user has permissions
        if (!$file->isUpdateable()) {
            return new JSONResponse(['message' => 'Cannot update this file'], Http::STATUS_FORBIDDEN);
        }

        // Create archive folder in the root of the user's configured timeline
        $timelinePath = Exif::removeExtraSlash(Exif::getPhotosPath($this->config, $uid));
        $timelineFolder = $userFolder->get($timelinePath);
        if (null === $timelineFolder || !$timelineFolder instanceof Folder) {
            return new JSONResponse(['message' => 'Cannot get timeline'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        if (!$timelineFolder->isCreatable()) {
            return new JSONResponse(['message' => 'Cannot create archive folder'], Http::STATUS_FORBIDDEN);
        }

        // Get path of current file relative to the timeline folder
        // remove timelineFolder path from start of file path
        $timelinePath = $timelineFolder->getPath(); // no trailing slash
        if (substr($file->getPath(), 0, \strlen($timelinePath)) !== $timelinePath) {
            return new JSONResponse(['message' => 'Files outside timeline cannot be archived'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        $relativePath = substr($file->getPath(), \strlen($timelinePath)); // has a leading slash

        // Final path of the file including the file name
        $destinationPath = '';

        // Check if we want to archive or unarchive
        $body = $this->request->getParams();
        $unarchive = isset($body['archive']) && false === $body['archive'];

        // Get if the file is already in the archive (relativePath starts with archive)
        $archiveFolderWithLeadingSlash = '/'.\OCA\Memories\Util::$ARCHIVE_FOLDER;
        if (substr($relativePath, 0, \strlen($archiveFolderWithLeadingSlash)) === $archiveFolderWithLeadingSlash) {
            // file already in archive, remove it instead
            $destinationPath = substr($relativePath, \strlen($archiveFolderWithLeadingSlash));
            if (!$unarchive) {
                return new JSONResponse(['message' => 'File already archived'], Http::STATUS_BAD_REQUEST);
            }
        } else {
            // file not in archive, put it in there
            $destinationPath = Exif::removeExtraSlash(\OCA\Memories\Util::$ARCHIVE_FOLDER.$relativePath);
            if ($unarchive) {
                return new JSONResponse(['message' => 'File not archived'], Http::STATUS_BAD_REQUEST);
            }
        }

        // Remove the filename
        $destinationFolders = explode('/', $destinationPath);
        array_pop($destinationFolders);

        // Create folder tree
        $folder = $timelineFolder;
        foreach ($destinationFolders as $folderName) {
            if ('' === $folderName) {
                continue;
            }

            try {
                $existingFolder = $folder->get($folderName.'/');
                if (!$existingFolder instanceof Folder) {
                    throw new \OCP\Files\NotFoundException('Not a folder');
                }
                $folder = $existingFolder;
            } catch (\OCP\Files\NotFoundException $e) {
                try {
                    $folder = $folder->newFolder($folderName);
                } catch (\OCP\Files\NotPermittedException $e) {
                    return new JSONResponse(['message' => 'Failed to create folder'], Http::STATUS_FORBIDDEN);
                }
            }
        }

        // Move file to archive folder
        try {
            $file->move($folder->getPath().'/'.$file->getName());
        } catch (\OCP\Files\NotPermittedException $e) {
            return new JSONResponse(['message' => 'Failed to move file'], Http::STATUS_FORBIDDEN);
        } catch (\OCP\Files\NotFoundException $e) {
            return new JSONResponse(['message' => 'File not found'], Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (\OCP\Files\InvalidPathException $e) {
            return new JSONResponse(['message' => 'Invalid path'], Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (\OCP\Lock\LockedException $e) {
            return new JSONResponse(['message' => 'File is locked'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse([], Http::STATUS_OK);
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
    public function setUserConfig(string $key, string $value): JSONResponse
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // Make sure not running in read-only mode
        if ($this->config->getSystemValue('memories.readonly', false)) {
            return new JSONResponse(['message' => 'Cannot change settings in readonly mode'], Http::STATUS_FORBIDDEN);
        }

        $userId = $user->getUid();
        $this->config->setUserValue($userId, Application::APPNAME, $key, $value);

        return new JSONResponse([], Http::STATUS_OK);
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function serviceWorker(): StreamResponse
    {
        $response = new StreamResponse(__DIR__.'/../../js/memories-service-worker.js');
        $response->setHeaders([
            'Content-Type' => 'application/javascript',
            'Service-Worker-Allowed' => '/',
        ]);
        $policy = new ContentSecurityPolicy();
        $policy->addAllowedWorkerSrcDomain("'self'");
        $policy->addAllowedScriptDomain("'self'");
        $policy->addAllowedConnectDomain("'self'");
        $response->setContentSecurityPolicy($policy);

        return $response;
    }

    /**
     * Get transformations depending on the request.
     *
     * @param bool $aggregateOnly Only apply transformations for aggregation (days call)
     */
    private function getTransformations(bool $aggregateOnly)
    {
        $transforms = [];

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
            $tagName = $this->request->getParam('tag');
            if ($tagName) {
                $transforms[] = [$this->timelineQuery, 'transformTagFilter', $tagName];
            }
        }

        // Limit number of responses for day query
        $limit = $this->request->getParam('limit');
        if ($limit) {
            $transforms[] = [$this->timelineQuery, 'transformLimitDay', (int) $limit];
        }

        return $transforms;
    }

    /** Preload a few "day" at the start of "days" response */
    private function preloadDays(array &$days, Folder &$folder, bool $recursive, bool $archive)
    {
        $uid = $this->userSession->getUser()->getUID();
        $transforms = $this->getTransformations(false);
        $preloaded = 0;
        $preloadDayIds = [];
        $preloadDays = [];
        foreach ($days as &$day) {
            if ($day['count'] <= 0) continue;

            $preloaded += $day['count'];
            $preloadDayIds[] = $day['dayid'];
            $preloadDays[] = &$day;

            if ($preloaded >= 50 || count($preloadDayIds) > 5) { // should be enough
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

    /** Get the Folder object relevant to the request */
    private function getRequestFolder()
    {
        $uid = $this->userSession->getUser()->getUID();

        try {
            $folder = null;
            $folderPath = $this->request->getParam('folder');
            $forcedTimelinePath = $this->request->getParam('timelinePath');
            $userFolder = $this->rootFolder->getUserFolder($uid);

            if (null !== $folderPath) {
                $folder = $userFolder->get($folderPath);
            } elseif (null !== $forcedTimelinePath) {
                $folder = $userFolder->get($forcedTimelinePath);
            } else {
                $configPath = Exif::removeExtraSlash(Exif::getPhotosPath($this->config, $uid));
                $folder = $userFolder->get($configPath);
            }

            if (!$folder instanceof Folder) {
                throw new \Exception('Folder not found');
            }
        } catch (\Exception $e) {
            return null;
        }

        return $folder;
    }

    /**
     * Check if albums are enabled for this user.
     */
    private function albumsIsEnabled(): bool
    {
        return $this->appManager->isEnabledForUser('photos');
    }

    /**
     * Check if tags is enabled for this user.
     */
    private function tagsIsEnabled(): bool
    {
        return $this->appManager->isEnabledForUser('systemtags');
    }

    /**
     * Check if recognize is enabled for this user.
     */
    private function recognizeIsEnabled(): bool
    {
        if (!$this->appManager->isEnabledForUser('recognize')) {
            return false;
        }

        $v = $this->appManager->getAppInfo('recognize')['version'];

        return version_compare($v, '3.0.0-alpha', '>=');
    }
}
