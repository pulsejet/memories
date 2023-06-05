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

class ArchiveController extends GenericApiController
{
    /**
     * @NoAdminRequired
     *
     * Move one file to the archive folder
     *
     * @param string fileid
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
            $timelineFolders = [];
            $timelinePaths = [];

            // Get all timeline paths
            foreach ($configPaths as $path) {
                try {
                    $f = $userFolder->get($path);
                    $timelineFolders[] = $f;
                    $timelinePaths[] = $f->getPath();
                } catch (\OCP\Files\NotFoundException $e) {
                    throw Exceptions::NotFound("timeline folder {$path}");
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
                $af = \OCA\Memories\Util::$ARCHIVE_FOLDER;
                $destinationPath = Util::sanitizePath($af.$relativeFilePath);
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
                        throw Exceptions::NotFound('Not a folder: '.$existingFolder->getPath());
                    }
                    $folder = $existingFolder;
                } catch (\OCP\Files\NotFoundException $e) {
                    try {
                        $folder = $this->createArchiveFolder($folder, $folderName);
                    } catch (\OCP\Files\NotPermittedException $e) {
                        throw Exceptions::ForbiddenFileUpdate($folder->getPath().' [create]');
                    }
                }
            }

            // Move file to archive folder
            $file->move($folder->getPath().'/'.$file->getName());

            return new JSONResponse([], Http::STATUS_OK);
        });
    }
    public function createArchiveFolder($folder, $folderName, int $maxRetries = 5, int $sleep = 1) {
        for ($try = 1; $try <= $maxRetries; $try++) {
            try {
                return $folder->newFolder($folderName);
            } catch (\OCP\Lock\LockedException $e) {
                if ($try >= $maxRetries) {
                    throw $e;
                }
                sleep($sleep);
            }
        }
    }
}
