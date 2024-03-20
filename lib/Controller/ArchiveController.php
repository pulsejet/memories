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

use OCA\Memories\Exceptions;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Folder;
use OCP\Lock\ILockingProvider;

class ArchiveController extends GenericApiController
{
    /**
     * @NoAdminRequired
     *
     * Move one file to the archive folder
     *
     * @param string $id File ID to archive / unarchive
     */
    public function archive(string $id): Http\Response
    {
        return Util::guardEx(function () use ($id) {
            $userFolder = Util::getUserFolder();

            // Check for permissions and get numeric Id
            $file = $userFolder->getById((int) $id);
            if (0 === \count($file)) {
                throw Exceptions::NotFound("file id {$id}");
            }
            $file = $file[0];

            // Check if user has permissions
            if (!$file->isUpdateable()) {
                throw Exceptions::ForbiddenFileUpdate($file->getName());
            }

            // Create archive folder in the root of the user's configured timeline
            $configPaths = Util::getTimelinePaths(Util::getUID());
            $timelinePaths = [];

            // Get all timeline paths
            foreach ($configPaths as $path) {
                try {
                    $timelinePaths[] = $userFolder->get($path)->getPath();
                } catch (\OCP\Files\NotFoundException $e) {
                    throw Exceptions::NotFound("timeline folder {$path}");
                }
            }

            // Bubble up from file until we reach the correct folder
            $fileStorageId = $file->getStorage()->getId();
            $parent = $file->getParent();
            $isArchived = false;
            $depth = 0;
            while (true) {
                /** @psalm-suppress DocblockTypeContradiction */
                if (null === $parent) {
                    throw new \Exception('Cannot get correct parent of file');
                }

                // Hit a timeline folder
                if (\in_array($parent->getPath(), $timelinePaths, true)) {
                    break;
                }

                // Hit the user's root folder
                if ($parent->getPath() === $userFolder->getPath()) {
                    break;
                }

                // Hit a storage root
                try {
                    if ($parent->getParent()->getStorage()->getId() !== $fileStorageId) {
                        break;
                    }
                } catch (\OCP\Files\NotFoundException $e) {
                    break;
                }

                // Hit an archive folder root
                if (Util::ARCHIVE_FOLDER === $parent->getName()) {
                    $isArchived = true;

                    break;
                }

                // Too deep
                if (++$depth > 32) {
                    throw new \Exception('[Archive] Max recursion depth exceeded');
                }

                $parent = $parent->getParent();
            }

            // Get path of current file relative to the parent folder
            $relativeFilePath = $parent->getRelativePath($file->getPath());
            if (!$relativeFilePath) {
                throw new \Exception('Cannot get relative path of file');
            }

            // Check if we want to archive or unarchive
            $body = $this->request->getParams();
            $unarchive = isset($body['archive']) && false === $body['archive'];
            if ($isArchived && !$unarchive) {
                throw Exceptions::BadRequest('File already archived');
            }
            if (!$isArchived && $unarchive) {
                throw Exceptions::BadRequest('File not archived');
            }

            // Final path of the file including the file name
            $destinationPath = '';

            // Get if the file is already in the archive (relativePath starts with archive)
            if ($isArchived) {
                // file already in archive, remove it
                $destinationPath = $relativeFilePath;
                $parent = $parent->getParent();
            } else {
                // file not in archive, put it in there
                $destinationPath = Util::sanitizePath(Util::ARCHIVE_FOLDER.$relativeFilePath);
                if (null === $destinationPath) {
                    throw Exceptions::BadRequest('Invalid archive destination path');
                }
            }

            // Remove the filename
            $destinationFolders = array_filter(explode('/', $destinationPath));
            array_pop($destinationFolders);

            // Create folder tree
            $folder = $parent;
            foreach ($destinationFolders as $folderName) {
                $folder = $this->getOrCreateFolder($folder, $folderName);
            }

            // Move file to archive folder
            $file->move($folder->getPath().'/'.$file->getName());

            return new JSONResponse([], Http::STATUS_OK);
        });
    }

    /**
     * Get or create a folder in the given parent folder.
     *
     * @param Folder $parent Parent folder
     * @param string $name   Folder name
     * @param int    $tries  Number of tries to create the folder
     *
     * @throws \OCA\Memories\HttpResponseException
     */
    private function getOrCreateFolder(Folder $parent, string $name, int $tries = 3): Folder
    {
        // Path of the folder we want to create (for error messages)
        $finalPath = $parent->getPath().'/'.$name;

        // Attempt to create the folder
        if (!$parent->nodeExists($name)) {
            $pathHash = md5($finalPath);
            $lockingProvider = \OC::$server->get(ILockingProvider::class);
            $lockKey = "memories/create/{$pathHash}";
            $lockType = ILockingProvider::LOCK_EXCLUSIVE;
            $locked = false;

            try {
                // Attempt to acquire exclusive lock
                $lockingProvider->acquireLock($lockKey, $lockType);
                $locked = true;
            } catch (\OCP\Lock\LockedException) {
                // Someone else is creating, wait and try to get the folder
                usleep(1000000);
            }

            if ($locked) {
                // Green light to create the folder
                try {
                    return $parent->newFolder($name);
                } catch (\OCP\Files\NotPermittedException $e) {
                    // Cannot create folder, throw error
                    throw Exceptions::ForbiddenFileUpdate("{$finalPath} [create]");
                } catch (\OCP\Lock\LockedException $e) {
                    // This is the Files lock ... well
                    throw Exceptions::ForbiddenFileUpdate("{$finalPath} [locked]");
                } finally {
                    // Release our lock
                    $lockingProvider->releaseLock($lockKey, $lockType);
                }
            }
        }

        // Second check if the folder exists
        if (!$parent->nodeExists($name)) {
            throw Exceptions::NotFound("Folder not found: {$finalPath}");
        }

        // Attempt to get the folder that should already exist
        $existing = $parent->get($name);
        if (!$existing instanceof Folder) {
            throw Exceptions::NotFound("Not a folder: {$existing->getPath()}");
        }

        return $existing;
    }
}
