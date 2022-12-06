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
        return new JSONResponse(['handle' => $this->createHandle($files)]);
    }

    /**
     * Get a handle for downloading files.
     *
     * @param int[] $files
     */
    public static function createHandle(array $files): string
    {
        $handle = \OC::$server->get(ISecureRandom::class)->generate(16, ISecureRandom::CHAR_ALPHANUMERIC);
        \OC::$server->get(ISession::class)->set("memories_download_ids_{$handle}", $files);

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
        $key = "memories_download_ids_{$handle}";
        $fileIds = $session->get($key);
        $session->remove($key);

        if (null === $fileIds) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

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
        $this->multiple($fileIds); // exits
    }

    /**
     * Download a single file.
     */
    private function one(int $fileid): Http\Response
    {
        $file = $this->getUserFile($fileid);
        if (null === $file) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        $response = new Http\StreamResponse($file->fopen('rb'));
        $response->addHeader('Content-Type', $file->getMimeType());
        $response->addHeader('Content-Disposition', 'attachment; filename="'.$file->getName().'"');
        $response->addHeader('Content-Length', $file->getSize());

        return $response;
    }

    /**
     * Download multiple files.
     *
     * @param int[] $fileIds
     */
    private function multiple(array &$fileIds)
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
        $streamer->sendHeaders('download');

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
