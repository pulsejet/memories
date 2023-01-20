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

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Db\TimelineRoot;
use OCA\Memories\Exif;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;

class ApiBase extends Controller
{
    protected IConfig $config;
    protected IUserSession $userSession;
    protected IRootFolder $rootFolder;
    protected IAppManager $appManager;
    protected TimelineQuery $timelineQuery;
    protected IDBConnection $connection;

    public function __construct(
        IRequest $request,
        IConfig $config,
        IUserSession $userSession,
        IDBConnection $connection,
        IRootFolder $rootFolder,
        IAppManager $appManager
    ) {
        parent::__construct(Application::APPNAME, $request);

        $this->config = $config;
        $this->userSession = $userSession;
        $this->connection = $connection;
        $this->rootFolder = $rootFolder;
        $this->appManager = $appManager;
        $this->timelineQuery = new TimelineQuery($connection);
    }

    /** Get logged in user's UID or throw exception */
    protected function getUID(): string
    {
        $user = $this->userSession->getUser();
        if ($this->getShareToken()) {
            $user = null;
        } elseif (null === $user) {
            throw new \Exception('User not logged in');
        }

        return $user ? $user->getUID() : '';
    }

    /** Get the TimelineRoot object relevant to the request */
    protected function getRequestRoot()
    {
        $user = $this->userSession->getUser();
        $root = new TimelineRoot();

        // Albums have no folder
        if ($this->albumsIsEnabled() && $this->request->getParam('album')) {
            if (null !== $user) {
                return $root;
            }
            if (($token = $this->getShareToken()) && $this->timelineQuery->getAlbumByLink($token)) {
                return $root;
            }
        }

        // Public shared folder
        if ($share = $this->getShareNode()) { // can throw
            $root->addFolder($share);

            return $root;
        }

        // Anything else needs a user
        if (null === $user) {
            throw new \Exception('User not logged in');
        }
        $uid = $user->getUID();

        $folder = null;
        $folderPath = $this->request->getParam('folder');
        $userFolder = $this->rootFolder->getUserFolder($uid);

        try {
            if (null !== $folderPath) {
                $folder = $userFolder->get(Exif::removeExtraSlash($folderPath));
                $root->addFolder($folder);
            } else {
                $timelinePath = $this->request->getParam('timelinePath', Exif::getPhotosPath($this->config, $uid));
                $timelinePath = Exif::removeExtraSlash($timelinePath);

                // Multiple timeline path support
                $paths = explode(';', $timelinePath);
                foreach ($paths as &$path) {
                    $folder = $userFolder->get(trim($path));
                    $root->addFolder($folder);
                }
                $root->addMountPoints();
            }
        } catch (\OCP\Files\NotFoundException $e) {
            $msg = $e->getMessage();

            throw new \Exception("Folder not found: {$msg}");
        }

        return $root;
    }

    /**
     * Get a file with ID for the current user.
     */
    protected function getUserFile(int $fileId): ?File
    {
        // Don't check self for share token
        if ($this->getShareToken()) {
            return $this->getShareFile($fileId);
        }

        // Check both user folder and album
        return $this->getUserFolderFile($fileId) ??
            $this->getAlbumFile($fileId);
    }

    /**
     * Get a file with ID from user's folder.
     */
    protected function getUserFolderFile(int $id): ?File
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return null;
        }
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());

        return $this->getOneFileFromFolder($userFolder, $id);
    }

    /**
     * Get a file with ID from an album.
     */
    protected function getAlbumFile(int $id): ?File
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return null;
        }
        $uid = $user->getUID();

        $owner = $this->timelineQuery->albumHasUserFile($uid, $id);
        if (!$owner) {
            return null;
        }

        $folder = $this->rootFolder->getUserFolder($owner);

        return $this->getOneFileFromFolder($folder, $id);
    }

    /**
     * Get a file with ID from a public share.
     *
     * @param int $fileId
     */
    protected function getShareFile(int $id): ?File
    {
        try {
            // Album share
            if ($this->request->getParam('album')) {
                $album = $this->timelineQuery->getAlbumByLink($this->getShareToken());
                if (null === $album) {
                    return null;
                }

                $owner = $this->timelineQuery->albumHasFile((int) $album['album_id'], $id);
                if (!$owner) {
                    return null;
                }

                $folder = $this->rootFolder->getUserFolder($owner);

                return $this->getOneFileFromFolder($folder, $id);
            }

            // Folder share
            if ($share = $this->getShareNode()) {
                return $this->getOneFileFromFolder($share, $id);
            }
        } catch (\Exception $e) {
        }

        return null;
    }

    protected function isRecursive()
    {
        return null === $this->request->getParam('folder') || $this->request->getParam('recursive');
    }

    protected function isArchive()
    {
        return null !== $this->request->getParam('archive');
    }

    protected function isMonthView()
    {
        return null !== $this->request->getParam('monthView');
    }

    protected function isReverse()
    {
        return null !== $this->request->getParam('reverse');
    }

    protected function getShareToken()
    {
        return $this->request->getParam('token');
    }

    protected function getShareObject()
    {
        // Get token from request
        $token = $this->getShareToken();
        if (null === $token) {
            return null;
        }

        // Get share by token
        $share = \OC::$server->get(\OCP\Share\IManager::class)->getShareByToken($token);
        if (!PublicController::validateShare($share)) {
            return null;
        }

        // Check if share is password protected
        if (($password = $share->getPassword()) !== null) {
            $session = \OC::$server->get(\OCP\ISession::class);

            // https://github.com/nextcloud/server/blob/0447b53bda9fe95ea0cbed765aa332584605d652/lib/public/AppFramework/PublicShareController.php#L119
            if (
                $session->get('public_link_authenticated_token') !== $token
                || $session->get('public_link_authenticated_password_hash') !== $password
            ) {
                throw new \Exception('Share is password protected and user is not authenticated');
            }
        }

        return $share;
    }

    protected function getShareNode()
    {
        $share = $this->getShareObject();
        if (null === $share) {
            return null;
        }

        // Get node from share
        $node = $share->getNode(); // throws exception if not found
        if (!$node instanceof Folder || !$node->isReadable() || !$node->isShareable()) {
            throw new \Exception('Share not found or invalid');
        }

        return $node;
    }

    /**
     * Check if albums are enabled for this user.
     */
    protected function albumsIsEnabled(): bool
    {
        return \OCA\Memories\Util::albumsIsEnabled($this->appManager);
    }

    /**
     * Check if tags is enabled for this user.
     */
    protected function tagsIsEnabled(): bool
    {
        return \OCA\Memories\Util::tagsIsEnabled($this->appManager);
    }

    /**
     * Check if recognize is enabled for this user.
     */
    protected function recognizeIsEnabled(): bool
    {
        return \OCA\Memories\Util::recognizeIsEnabled($this->appManager);
    }

    // Check if facerecognition is installed and enabled for this user.
    protected function facerecognitionIsInstalled(): bool
    {
        return \OCA\Memories\Util::facerecognitionIsInstalled($this->appManager);
    }

    /**
     * Check if facerecognition is enabled for this user.
     */
    protected function facerecognitionIsEnabled(): bool
    {
        return \OCA\Memories\Util::facerecognitionIsEnabled($this->config, $this->getUID());
    }

    /**
     * Helper to get one file or null from a fiolder.
     */
    private function getOneFileFromFolder(Folder $folder, int $id): ?File
    {
        // Check for permissions and get numeric Id
        $file = $folder->getById($id);
        if (0 === \count($file)) {
            return null;
        }

        // Check if node is a file
        if (!$file[0] instanceof File) {
            return null;
        }

        // Check read permission
        if (!($file[0]->getPermissions() & \OCP\Constants::PERMISSION_READ)) {
            return null;
        }

        return $file[0];
    }
}
