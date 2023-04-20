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
use OCA\Memories\Exceptions;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ISession;
use OCP\ITempManager;
use OCP\Security\ISecureRandom;

class DownloadController extends GenericApiController
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
    public function request(): Http\Response
    {
        return Util::guardEx(function () {
            // Get ids from body
            $files = $this->request->getParam('files');
            if (null === $files || !\is_array($files)) {
                throw Exceptions::MissingParameter('files');
            }

            // Return id
            $handle = self::createHandle('memories', $files);

            return new JSONResponse(['handle' => $handle]);
        });
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
        return Util::guardEx(function () use ($handle) {
            // Get ids from request
            $session = \OC::$server->get(ISession::class);
            $key = "memories_download_{$handle}";
            $info = $session->get($key);
            $session->remove($key);

            if (null === $info) {
                return Exceptions::NotFound('handle');
            }

            $name = $info[0].'-'.date('YmdHis');
            $fileIds = $info[1];

            /** @var int[] $fileIds */
            $fileIds = array_filter(array_map('intval', $fileIds), fn ($id) => $id > 0);

            // Check if we have any valid ids
            if (0 === \count($fileIds)) {
                return Exceptions::NotFound('file IDs');
            }

            // Download single file
            if (1 === \count($fileIds)) {
                return $this->one($fileIds[0], false);
            }

            // Download multiple files
            return $this->multiple($name, $fileIds);
        });
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     */
    public function one(int $fileid, bool $resumable = true, int $numChunks = 0): Http\Response
    {
        return Util::guardExDirect(function (Http\IOutput $out) use ($fileid, $resumable, $numChunks) {
            $file = $this->fs->getUserFile($fileid);

            // check if http_range is sent by browser
            $range = $this->request->getHeader('Range');
            if (!empty($range)) {
                [$sizeUnit, $rangeOrig] = Util::explode_exact('=', $range, 2);
                if ('bytes' === $sizeUnit) {
                    // http://tools.ietf.org/id/draft-ietf-http-range-retrieval-00.txt
                    [$range, $extra] = Util::explode_exact(',', $rangeOrig, 2);
                }
            }

            // If not resumable, discard the range
            if (!$resumable) {
                $range = '';
            }

            // Get file reading parameters
            $size = $file->getSize();
            [$seekStart, $seekEnd] = Util::explode_exact('-', $range, 2);
            $seekEnd = (empty($seekEnd)) ? ($size - 1) : min(abs((int) $seekEnd), $size - 1);
            $seekStart = (empty($seekStart) || $seekEnd < abs((int) $seekStart)) ? 0 : max(abs((int) $seekStart), 0);

            // If the client knows about ranges, only send a maximum of X KB
            // This way we don't read the whole file for no reason
            if (!empty($range)) {
                // Default to 64MB
                $maxLen = 64 * 1024 * 1024;

                // For videos, use a max of 8MB
                if ('video' === $this->request->getHeader('Sec-Fetch-Dest')) {
                    $maxLen = 8 * 1024 * 1024;
                }

                // Check if the client sent a hint for the chunk size
                if ($numChunks) {
                    $maxLen = min(ceil($size / $numChunks), $maxLen * 3);
                }

                // No less than 1MB; this is just wasteful
                $maxLen = max($maxLen, 1024 * 1024);

                $seekEnd = min($seekEnd, $seekStart + $maxLen);
            }

            // Only send partial content header if downloading a piece of the file
            if ($seekStart > 0 || $seekEnd < ($size - 1)) {
                $out->setHeader('HTTP/1.1 206 Partial Content');
            }

            // Set headers
            $out->setHeader('Accept-Ranges: bytes');
            $out->setHeader("Content-Range: bytes {$seekStart}-{$seekEnd}/{$size}");
            $out->setHeader('Content-Length: '.($seekEnd - $seekStart + 1));
            $out->setHeader('Content-Type: '.$file->getMimeType());

            // Make sure the browser downloads the file
            $out->setHeader('Content-Disposition: attachment; filename="'.$file->getName().'"');

            // Open file to send
            $res = $file->fopen('rb');

            // Seek to start if not zero
            if ($seekStart > 0) {
                fseek($res, $seekStart);
            }

            // Handle aborts manually
            ignore_user_abort(true);

            // Send 1MB at a time
            // But send 256KB initially in case loading metadata only
            $chunkRead = 0;

            // Start output buffering
            ob_start();

            while (!feof($res) && $seekStart <= $seekEnd) {
                $lenLeft = $seekEnd - $seekStart + 1;
                $buffer = fread($res, min(1024 * 1024, $lenLeft));
                if (false === $buffer) {
                    break;
                }
                $seekStart += \strlen($buffer);
                $chunkRead += \strlen($buffer);

                // Send buffer
                $out->setOutput($buffer);

                // Flush output if chunk is large enough
                if ($chunkRead > 1024 * 512) {
                    // Check if client disconnected
                    if (CONNECTION_NORMAL !== connection_status() || connection_aborted()) {
                        break;
                    }

                    // Flush output
                    ob_flush();
                    $chunkRead = 0;
                }
            }

            // Flush remaining output
            ob_end_flush();

            // Close file
            fclose($res);
        });
    }

    /**
     * Download multiple files.
     *
     * @param string $name    Name of zip file
     * @param int[]  $fileIds
     */
    private function multiple(string $name, array $fileIds): Http\Response
    {
        return Util::guardExDirect(function ($out) use ($name, $fileIds) {
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

            /** @var ITempManager for clearing temp files */
            $tempManager = \OC::$server->get(ITempManager::class);

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
                    $file = $this->fs->getUserFile($fileId);
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

                    // Clear any temp files
                    $tempManager->clean();
                }
            }

            // Restore time limit
            @set_time_limit($executionTime);

            // Done
            $streamer->finalize();
        });
    }
}
