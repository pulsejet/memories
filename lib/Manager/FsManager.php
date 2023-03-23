<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2023 Varun Patil <radialapps@gmail.com>
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

namespace OCA\Memories\Manager;

use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Db\TimelineRoot;
use OCA\Memories\Exif;
use OCA\Memories\Util;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IShare;

class FsManager
{
    protected IConfig $config;
    protected IUserSession $userSession;
    protected IRootFolder $rootFolder;
    protected TimelineQuery $timelineQuery;
    protected IRequest $request;

    public function __construct(
        IConfig $config,
        IUserSession $userSession,
        IRootFolder $rootFolder,
        TimelineQuery $timelineQuery,
        IRequest $request
    ) {
        $this->config = $config;
        $this->userSession = $userSession;
        $this->rootFolder = $rootFolder;
        $this->timelineQuery = $timelineQuery;
        $this->request = $request;
    }

    /** Get the TimelineRoot object relevant to the request */
    public function populateRoot(TimelineRoot &$root)
    {
        $user = $this->userSession->getUser();

        // Albums have no folder
        if ($this->request->getParam('album') && Util::albumsIsEnabled()) {
            if (null !== $user) {
                return $root;
            }
            if (($token = $this->getShareToken()) && $this->timelineQuery->getAlbumByLink($token)) {
                return $root;
            }
        }

        // Public shared folder
        if ($share = $this->getShareNode()) { // can throw
            if (!$share instanceof Folder) {
                throw new \Exception('Share is not a folder');
            }

            $root->addFolder($share);

            return $root;
        }

        // Anything else needs a user
        if (null === $user) {
            throw new \Exception('User not logged in: no timeline root');
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
    public function getUserFile(int $fileId): ?File
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
    public function getUserFolderFile(int $id): ?File
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return null;
        }
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());

        // No need to force permissions when reading
        // from the user's own folder. This includes shared
        // folders and files from other users.
        return $this->getOneFileFromFolder($userFolder, $id);
    }

    /**
     * Get a file with ID from an album.
     *
     * @param int $id FileID
     */
    public function getAlbumFile(int $id): ?File
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

        // Album files are always read-only
        // Note that albums have lowest priority, so it means the
        // user doesn't have access to the file in their own folder.
        return $this->getOneFileFromFolder($folder, $id, \OCP\Constants::PERMISSION_READ);
    }

    /**
     * Get a file with ID from a public share.
     *
     * @param int $id FileID
     */
    public function getShareFile(int $id): ?File
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

                // Public albums are always read-only
                return $this->getOneFileFromFolder($folder, $id, \OCP\Constants::PERMISSION_READ);
            }

            // Folder share
            if ($share = $this->getShareNode()) {
                // Public shares may allow editing
                // Just use the same permissions as the share
                if ($share instanceof File) {
                    return $share;
                }
                if ($share instanceof Folder) {
                    return $this->getOneFileFromFolder($share, $id, $share->getPermissions());
                }

                return null;
            }
        } catch (\Exception $e) {
        }

        return null;
    }

    public function getShareObject()
    {
        // Get token from request
        $token = $this->getShareToken();
        if (null === $token) {
            return null;
        }

        // Get share by token
        $share = \OC::$server->get(\OCP\Share\IManager::class)->getShareByToken($token);
        if (!self::validateShare($share)) {
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

    public function getShareNode()
    {
        $share = $this->getShareObject();
        if (null === $share) {
            return null;
        }

        // Get node from share
        $node = $share->getNode(); // throws exception if not found
        if (!$node->isReadable() || !$node->isShareable()) {
            throw new \Exception('Share not found or invalid');
        }

        // Force permissions from the share onto the node
        Util::forcePermissions($node, $share->getPermissions());

        return $node;
    }

    /**
     * Validate the permissions of the share.
     */
    public static function validateShare(?IShare $share): bool
    {
        if (null === $share) {
            return false;
        }

        // Get user manager
        $userManager = \OC::$server->get(IUserManager::class);

        // Check if share read is allowed
        if (!($share->getPermissions() & \OCP\Constants::PERMISSION_READ)) {
            return false;
        }

        // If the owner is disabled no access to the linke is granted
        $owner = $userManager->get($share->getShareOwner());
        if (null === $owner || !$owner->isEnabled()) {
            return false;
        }

        // If the initiator of the share is disabled no access is granted
        $initiator = $userManager->get($share->getSharedBy());
        if (null === $initiator || !$initiator->isEnabled()) {
            return false;
        }

        return $share->getNode()->isReadable() && $share->getNode()->isShareable();
    }

    /**
     * Helper to get one file or null from a fiolder.
     *
     * @param Folder $folder Folder to search in
     * @param int    $id     Id of the file
     * @param int    $perm   Permissions to force on the file
     */
    private function getOneFileFromFolder(Folder $folder, int $id, int $perm = -1): ?File
    {
        // Check for permissions and get numeric Id
        $file = $folder->getById($id);
        if (0 === \count($file)) {
            return null;
        }
        $file = $file[0];

        // Check if node is a file
        if (!$file instanceof File) {
            return null;
        }

        // Check read permission
        if (!$file->isReadable()) {
            return null;
        }

        // Force file permissions if required
        if ($perm >= 0) {
            Util::forcePermissions($file, $perm);
        }

        return $file;
    }

    private function getShareToken()
    {
        return $this->request->getParam('token');
    }
}
