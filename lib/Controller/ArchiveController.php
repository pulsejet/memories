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
        if (!$file->isUpdateable() || !($file->getPermissions() & \OCP\Constants::PERMISSION_UPDATE)) {
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
}
