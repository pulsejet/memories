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

use bantu\IniGetWrapper\IniGetWrapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ISession;
use OCP\Security\ISecureRandom;

class DownloadController extends ApiBase
{
    /**
     * @NoAdminRequired
     *
     * @PublicPage
     *
     * @UseSession
     *
     * Request to download one or more files
     */
    public function request(): JSONResponse
    {
        // Get ids from body
        $files = $this->request->getParam('files');
        if (null === $files || !\is_array($files)) {
            return new JSONResponse([], Http::STATUS_BAD_REQUEST);
        }

        // Return id
        $handle = self::createHandle('memories', $files);

        return new JSONResponse(['handle' => $handle]);
    }

    /**
     * Get a handle for downloading files.
     *
     * The calling controller must have the UseSession annotation.
     *
     * @param string $name  Name of zip file
     * @param int[]  $files
     */
    public static function createHandle(string $name, array $files): string
    {
        $handle = \OC::$server->get(ISecureRandom::class)->generate(16, ISecureRandom::CHAR_ALPHANUMERIC);
        \OC::$server->get(ISession::class)->set("memories_download_{$handle}", [$name, $files]);

        return $handle;
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * Download one or more files
     */
    public function file(string $handle): Http\Response
    {
        // Get ids from request
        $session = \OC::$server->get(ISession::class);
        $key = "memories_download_{$handle}";
        $info = $session->get($key);
        $session->remove($key);

        if (null === $info) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }
        $name = $info[0].'-'.date('YmdHis');
        $fileIds = $info[1];

        /** @var int[] $fileIds */
        $fileIds = array_filter(array_map('intval', $fileIds), function (int $id): bool {
            return $id > 0;
        });

        // Check if we have any valid ids
        if (0 === \count($fileIds)) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Download single file
        if (1 === \count($fileIds)) {
            return $this->one($fileIds[0]);
        }

        // Download multiple files
        $this->multiple($name, $fileIds); // exits
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     */
    public function one(int $fileid): Http\Response
    {
        $file = $this->getUserFile($fileid);
        if (null === $file) {
            return new JSONResponse([
                'message' => 'File not found',
            ], Http::STATUS_NOT_FOUND);
        }

        // Get the owner's root folder
        $owner = $file->getOwner()->getUID();
        $userFolder = $this->rootFolder->getUserFolder($owner);

        // Get the file in the context of the owner
        $ownerFile = $userFolder->getById($fileid);
        if (0 === \count($ownerFile)) {
            // This should never happen, since the file was already found earlier
            // Except if it was deleted in the meantime ...
            return new JSONResponse([
                'message' => 'File not found in owner\'s root folder',
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // Get DAV path of file relative to owner's root folder
        $path = $userFolder->getRelativePath($ownerFile[0]->getPath());
        if (null === $path) {
            return new JSONResponse([
                'message' => 'File path not found in owner\'s root folder',
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // Setup filesystem for owner
        \OC_Util::tearDownFS();
        \OC_Util::setupFS($owner);

        // HEAD and RANGE support
        $server_params = ['head' => 'HEAD' === $this->request->getMethod()];
        if (isset($_SERVER['HTTP_RANGE'])) {
            $server_params['range'] = $this->request->getHeader('Range');
        }

        // Write file to output and exit
        \OC_Files::get(\dirname($path), basename($path), $server_params);

        exit;
    }

    /**
     * Download multiple files.
     *
     * @param string $name    Name of zip file
     * @param int[]  $fileIds
     */
    private function multiple(string $name, array &$fileIds)
    {
        // Disable time limit
        $executionTime = (int) \OC::$server->get(IniGetWrapper::class)->getNumeric('max_execution_time');
        @set_time_limit(0);

        // Ensure we can abort the request if user stops it
        ignore_user_abort(true);

        // Pretend the size is huge so forced zip64
        // Lookup the constructor of \OC\Streamer for more info
        $size = \count($fileIds) * 1024 * 1024 * 1024 * 8;
        $streamer = new \OC\Streamer($this->request, $size, \count($fileIds));

        // Create a zip file
        $streamer->sendHeaders($name);

        // Multiple files might have the same name
        // So we need to add a number to the end of the name
        $nameCounts = [];

        // Send each file
        foreach ($fileIds as $fileId) {
            if (connection_aborted()) {
                break;
            }

            /** @var bool|resource */
            $handle = false;

            /** @var ?File */
            $file = null;

            /** @var ?string */
            $name = (string) $fileId;

            try {
                // This checks permissions
                $file = $this->getUserFile($fileId);
                if (null === $file) {
                    throw new \Exception('File not found');
                }
                $name = $file->getName();

                // Open file
                $handle = $file->fopen('rb');
                if (false === $handle) {
                    throw new \Exception('Failed to open file');
                }

                // Handle duplicate names
                if (isset($nameCounts[$name])) {
                    ++$nameCounts[$name];

                    // add count before extension
                    $extpos = strrpos($name, '.');
                    if (false === $extpos) {
                        $name .= " ({$nameCounts[$name]})";
                    } else {
                        $name = substr($name, 0, $extpos)." ({$nameCounts[$name]})".substr($name, $extpos);
                    }
                } else {
                    $nameCounts[$name] = 0;
                }

                // Add file to zip
                if (!$streamer->addFileFromStream(
                    $handle,
                    $name,
                    $file->getSize(),
                    $file->getMTime(),
                )) {
                    throw new \Exception('Failed to add file to zip');
                }
            } catch (\Exception $e) {
                // create a dummy memory file with the error message
                $dummy = fopen('php://memory', 'rw+');
                fwrite($dummy, $e->getMessage());
                rewind($dummy);

                $streamer->addFileFromStream(
                    $dummy,
                    "{$name}_error.txt",
                    \strlen($e->getMessage()),
                    time(),
                );

                // close the dummy file
                fclose($dummy);
            } finally {
                if (false !== $handle) {
                    fclose($handle);
                }
            }
        }

        // Restore time limit
        @set_time_limit($executionTime);

        // Done
        $streamer->finalize();

        exit;
    }
}
