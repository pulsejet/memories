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

use OCA\Memories\Exif;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\Folder;

class ArchiveController extends ApiBase
{
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
        $configPath = Exif::removeExtraSlash(Exif::getPhotosPath($this->config, $uid));
        $configPaths = explode(';', $configPath);
        $timelineFolders = [];
        $timelinePaths = [];

        // Get all timeline paths
        foreach ($configPaths as $path) {
            try {
                $f = $userFolder->get($path);
                $timelineFolders[] = $f;
                $timelinePaths[] = $f->getPath();
            } catch (\OCP\Files\NotFoundException $e) {
                return new JSONResponse(['message' => 'Timeline folder not found'], Http::STATUS_NOT_FOUND);
            }
        }

        // Bubble up from file until we reach the correct folder
        $fileStorageId = $file->getStorage()->getId();
        $parent = $file->getParent();
        $isArchived = false;
        while (true) {
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
            if ($parent->getName() === \OCA\Memories\Util::$ARCHIVE_FOLDER) {
                $isArchived = true;

                break;
            }

            $parent = $parent->getParent();
        }

        // Get path of current file relative to the parent folder
        $relativeFilePath = $parent->getRelativePath($file->getPath());

        // Check if we want to archive or unarchive
        $body = $this->request->getParams();
        $unarchive = isset($body['archive']) && false === $body['archive'];
        if ($isArchived && !$unarchive) {
            return new JSONResponse(['message' => 'File already archived'], Http::STATUS_BAD_REQUEST);
        }
        if (!$isArchived && $unarchive) {
            return new JSONResponse(['message' => 'File not archived'], Http::STATUS_BAD_REQUEST);
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
            $af = \OCA\Memories\Util::$ARCHIVE_FOLDER;
            $destinationPath = Exif::removeExtraSlash($af.$relativeFilePath);
        }

        // Remove the filename
        $destinationFolders = array_filter(explode('/', $destinationPath));
        array_pop($destinationFolders);

        // Create folder tree
        $folder = $parent;
        foreach ($destinationFolders as $folderName) {
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
}
