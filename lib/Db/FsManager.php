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

namespace OCA\Memories\Db;

use OC\Files\Search\SearchBinaryOperator;
use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchQuery;
use OCA\Memories\ClustersBackend;
use OCA\Memories\Exceptions;
use OCA\Memories\Util;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IShare;

class FsManager
{
    private ICache $nomediaCache;

    public function __construct(
        private IConfig $config,
        private IUserSession $userSession,
        private IRootFolder $rootFolder,
        private AlbumsQuery $albumsQuery,
        private IRequest $request,
        ICacheFactory $cacheFactory,
    ) {
        $this->nomediaCache = $cacheFactory->createLocal('memories:nomedia');
    }

    /**
     * Populate TimelineRoot object relevant to the request.
     *
     * @param TimelineRoot $root      Root object to populate (by reference)
     * @param bool         $recursive Whether to get the folders recursively
     */
    public function populateRoot(TimelineRoot &$root, bool $recursive = true): TimelineRoot
    {
        $user = $this->userSession->getUser();

        // Albums have no folder
        if ($this->hasAlbumToken() && Util::albumsIsEnabled()) {
            if (null !== $user) {
                return $root;
            }
            if (($token = $this->getShareToken()) && $this->albumsQuery->getAlbumByLink($token)) {
                return $root;
            }
        }

        // Public shared folder
        if ($share = $this->getShareNode()) { // can throw
            if (!$share instanceof Folder) {
                throw new \Exception('Share is not a folder');
            }

            // Folder inside shared folder
            if ($path = $this->getRequestFolder()) {
                $sanitized = Util::sanitizePath($path);
                if (null === $sanitized) {
                    throw new \Exception("Invalid parameter path: {$path}");
                }

                // Get subnode from share
                try {
                    $share = $share->get($sanitized);
                } catch (\OCP\Files\NotFoundException $e) {
                    throw new \Exception("Folder not found: {$e->getMessage()}");
                }
            }

            // This internally checks if the node is a folder
            $root->addFolder($share);

            return $root;
        }

        // Anything else needs a user
        if (null === $user) {
            throw Exceptions::NotLoggedIn();
        }

        // Get UID and user's folder
        $uid = $user->getUID();
        $userFolder = $this->rootFolder->getUserFolder($uid);

        /** @var string[] $paths List of paths to add to root */
        $paths = [];
        if ($path = $this->getRequestFolder()) {
            $paths = [$path];
        } else {
            $paths = Util::getTimelinePaths($uid);
        }

        // Combined etag, for cache invalidation.
        // This is cheaper and more sensible than the root etag.
        // The only time this breaks down is if the user places a .nomedia
        // outside the timeline path; rely on expiration for that.
        $etag = $uid;

        try {
            foreach ($paths as $path) {
                if ($sanitized = Util::sanitizePath($path)) {
                    $node = $userFolder->get($sanitized);
                    $root->addFolder($node);
                    $etag .= $node->getEtag();
                } else {
                    throw new \Exception("invalid path {$path}");
                }
            }
        } catch (\Exception $e) {
            throw new \Exception("Folder not found: {$e->getMessage()}");
        }

        // Add shares or external stores inside the current folders
        // if this is a recursive query (e.g. timeline)
        if ($recursive) {
            $root->addMountPoints();

            // Exclude .nomedia folders
            //
            // This is needed to be done despite the exlusion in the CTE to account
            // for mount points inside folders with a .nomedia file. For example:
            //  /user/files/timeline-path/
            //     => subfolder1
            //        => photo1
            //     => subfolder2
            //        => .nomedia
            //        => external-mount   <-- this is a separate topFolder in the CTE
            //           => photo2        <-- this should be excluded, but CTE cannot find this
            $root->excludePaths($this->getNoMediaFolders($userFolder, md5($etag)));
        }

        return $root;
    }

    /**
     * Get list of folders with .nomedia file.
     *
     * @param Folder $root root folder
     * @param string $key  cache key
     *
     * @return string[] List of paths
     */
    public function getNoMediaFolders(Folder $root, string $key): array
    {
        if (null !== ($paths = $this->nomediaCache->get($key))) {
            return $paths;
        }

        $comp = new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_OR, [
            new SearchComparison(ISearchComparison::COMPARE_EQUAL, 'name', '.nomedia'),
            new SearchComparison(ISearchComparison::COMPARE_EQUAL, 'name', '.nomemories'),
        ]);
        $search = $root->search(new SearchQuery($comp, 0, 0, [], Util::getUser()));

        $paths = array_unique(array_map(static fn (Node $node) => \dirname($node->getPath()), $search));
        $this->nomediaCache->set($key, $paths, 60 * 60); // 1 hour

        return $paths;
    }

    /**
     * Get a file with ID for the current user.
     *
     * @throws \OCA\Memories\HttpResponseException
     */
    public function getUserFile(int $fileId): File
    {
        $file = $this->getUserFileOrNull($fileId);
        if (null === $file) {
            throw Exceptions::NotFoundFile($fileId);
        }

        return $file;
    }

    /**
     * Get a file with ID for the current user.
     */
    public function getUserFileOrNull(int $fileId): ?File
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

        $owner = $this->albumsQuery->userHasFile($uid, $id);
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
            if ($this->hasAlbumToken() && ($token = $this->getShareToken())) {
                $album = $this->albumsQuery->getAlbumByLink($token);
                if (null === $album) {
                    return null;
                }

                $owner = $this->albumsQuery->hasFile((int) $album['album_id'], $id);
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

    public function getShareObject(): ?IShare
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
        if (!empty($password = $share->getPassword())) {
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

    /**
     * Get the share node from the request.
     */
    public function getShareNode(): ?Node
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
     * Check if the user is allowed to download a node.
     *
     * @param Node $node Node to check
     */
    public function canDownload(Node $node): bool
    {
        // Check if the file is readable
        if (!$node->isReadable()) {
            return false;
        }

        // Share-specific properties
        // https://github.com/nextcloud/server/blob/024f689c97beca74f64db8d25fe82dcb9ef8441d/apps/dav/lib/Connector/Sabre/Node.php#L337-L345
        try {
            if (!class_exists('\OCA\Files_Sharing\SharedStorage')) {
                throw new \Exception('SharedStorage not installed');
            }

            if (($storage = $node->getStorage()) && $storage->instanceOfStorage(\OCA\Files_Sharing\SharedStorage::class)) {
                /** @var \OCA\Files_Sharing\SharedStorage $storage */
                $attributes = $storage->getShare()->getAttributes();

                // Check if download is disabled
                if (false === $attributes?->getAttribute('permissions', 'download')) {
                    return false;
                }
            }
        } catch (\Exception) {
            // Ignore
        }

        return true;
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

        /** @var File */
        return $file;
    }

    private function hasAlbumToken(): bool
    {
        return null !== $this->request->getParam(ClustersBackend\AlbumsBackend::clusterType(), null);
    }

    private function getShareToken(): ?string
    {
        return $this->request->getParam('token', null);
    }

    private function getRequestFolder(): ?string
    {
        return $this->request->getParam('folder', null);
    }
}
