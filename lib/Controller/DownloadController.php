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
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ISession;
use OCP\ITempManager;
use OCP\Security\ISecureRandom;

final class DownloadController extends GenericApiController
{
    /**
     * Request to download one or more files.
     *
     * @param int[] $files List of file IDs
     */
    #[NoAdminRequired]
    #[PublicPage]
    #[UseSession]
    public function request(array $files): Http\Response
    {
        return Util::guardEx(static function () use ($files) {
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
     * @param int[]  $files List of file IDs
     */
    public static function createHandle(string $name, array $files): string
    {
        $handle = \OC::$server->get(ISecureRandom::class)->generate(16, ISecureRandom::CHAR_ALPHANUMERIC);
        \OC::$server->get(ISession::class)->set("memories_download_{$handle}", [$name, $files]);

        return $handle;
    }

    /**
     * Download one or more files.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function file(string $handle): Http\Response
    {
        return Util::guardEx(function () use ($handle) {
            // Get ids from request
            $session = \OC::$server->get(ISession::class);
            $key = "memories_download_{$handle}";
            $info = $session->get($key);

            // Remove handle from session unless HEAD request
            if ('HEAD' !== $this->request->getMethod()) {
                $session->remove($key);
            }

            if (null === $info) {
                throw Exceptions::NotFound('handle');
            }

            $name = $info[0].'-'.date('YmdHis');
            $fileIds = $info[1];

            /** @var int[] $fileIds */
            $fileIds = array_filter(array_map('intval', $fileIds), static fn ($id) => $id > 0);

            // Check if we have any valid ids
            if (0 === \count($fileIds)) {
                throw Exceptions::NotFound('file IDs');
            }

            // Download single file
            if (1 === \count($fileIds)) {
                return $this->one($fileIds[0], false);
            }

            // Download multiple files
            return $this->multiple($name, $fileIds);
        });
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function one(int $fileid, bool $resumable = true): Http\Response
    {
        return Util::guardExDirect(function (Http\IOutput $out) use ($fileid, $resumable) {
            $file = $this->fs->getUserFile($fileid);

            // Check if we're allowed to download the file
            if (!$this->fs->canDownload($file)) {
                throw new \Exception("Download forbidden: {$file->getName()}");
            }

            // Get file reading parameters
            $size = (int) $file->getSize();
            $mimeType = $file->getMimeType();
            $isMedia = str_starts_with($mimeType, 'video/') || str_starts_with($mimeType, 'audio/');

            $rangeHeader = $this->request->getHeader('Range');
            $isRange = false;
            $seekStart = 0;
            $seekEnd = max(0, $size - 1);

            $sendRangeNotSatisfiable = static function () use ($out, $size): void {
                $out->setHeader('HTTP/1.1 416 Range Not Satisfiable');
                $out->setHeader("Content-Range: bytes */{$size}");
                $out->setHeader('Accept-Ranges: bytes');
                $out->setHeader('Content-Length: 0');
            };

            if ($resumable && !empty($rangeHeader)) {
                // Parse Range header: bytes=... (take first range if comma-separated)
                if (preg_match('/^\s*bytes\s*=\s*([^,\s]+)/i', $rangeHeader, $matches)) {
                    $spec = $matches[1];

                    // 1. Suffix range: -N (e.g. bytes=-500 -> last 500 bytes)
                    if (preg_match('/^-(\d+)$/', $spec, $m)) {
                        $suffixLength = (int) $m[1];
                        if ($suffixLength <= 0 || 0 === $size) {
                            $sendRangeNotSatisfiable();

                            return;
                        }
                        $isRange = true;
                        $seekStart = max(0, $size - $suffixLength);
                        $seekEnd = $size - 1;
                    }
                    // 2. Open-ended range: N- (e.g. bytes=500- or bytes=0-)
                    elseif (preg_match('/^(\d+)-$/', $spec, $m)) {
                        $start = (int) $m[1];
                        if ($start >= $size) {
                            $sendRangeNotSatisfiable();

                            return;
                        }
                        $isRange = true;
                        $seekStart = $start;
                        $seekEnd = $size - 1;
                    }
                    // 3. Closed range: N-M (e.g. bytes=0-499)
                    elseif (preg_match('/^(\d+)-(\d+)$/', $spec, $m)) {
                        $start = (int) $m[1];
                        $end = (int) $m[2];
                        if ($start > $end || $start >= $size) {
                            $sendRangeNotSatisfiable();

                            return;
                        }
                        $isRange = true;
                        $seekStart = $start;
                        $seekEnd = min($end, $size - 1);
                    }

                    // Malformed/unrecognized byte-range syntax is ignored per RFC 9110 (falls back to full content)
                }
            }

            // Send partial content header if a range was requested
            if ($isRange) {
                $out->setHeader('HTTP/1.1 206 Partial Content');
                $out->setHeader("Content-Range: bytes {$seekStart}-{$seekEnd}/{$size}");
            }

            // Accept ranges only if resumable
            if ($resumable) {
                $out->setHeader('Accept-Ranges: bytes');
            }

            // Set headers
            $contentLength = (0 === $size) ? 0 : ($seekEnd - $seekStart + 1);
            $out->setHeader('Content-Length: '.(string) $contentLength);
            $out->setHeader('Content-Type: '.$mimeType);

            // Range-seeking media players need a validator and permission to cache
            if ($etag = $file->getEtag()) {
                $out->setHeader('ETag: "'.$etag.'"');
            }
            if ($mtime = $file->getMTime()) {
                $out->setHeader('Last-Modified: '.gmdate('D, d M Y H:i:s', $mtime).' GMT');
            }
            $out->setHeader('Cache-Control: private, max-age=3600');

            // Play media inline for in-browser playback; force a download for everything else
            $filename = str_replace('"', '\"', $file->getName());
            $disposition = $isMedia && $resumable ? 'inline' : 'attachment';
            $out->setHeader("Content-Disposition: {$disposition}; filename=\"{$filename}\"");

            // Prevent output from being buffered
            $out->setHeader('X-Accel-Buffering: no');

            // Quit if HEAD request or empty file
            if ('HEAD' === $this->request->getMethod() || 0 === $size) {
                return;
            }

            // Open file to send
            $res = $file->fopen('rb');
            if (false === $res) {
                throw new \Exception('Failed to open file on disk');
            }

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

            // Disable time limit
            @set_time_limit(0);

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
            @set_time_limit(0);

            // Ensure we can abort the request if user stops it
            ignore_user_abort(true);

            // Create zip streamer
            $streamer = new \ZipStreamer\ZipStreamer(['zip64' => true]);

            // Create a zip file
            $streamer->sendHeaders("{$name}.zip");

            // Quit if HEAD request
            if ('HEAD' === $this->request->getMethod()) {
                return;
            }

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

                /** @var false|resource */
                $handle = false;

                /** @var ?\OCP\Files\File */
                $file = null;

                /** @var ?string */
                $name = (string) $fileId;

                try {
                    // This checks permissions
                    $file = $this->fs->getUserFile($fileId);
                    $name = $file->getName();

                    // Check if we're allowed to download the file
                    if (!$this->fs->canDownload($file)) {
                        throw new \Exception("Download forbidden: {$file->getName()}");
                    }

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
                    if (!$streamer->addFileFromStream($handle, $name, [])) {
                        throw new \Exception('Failed to add file to zip');
                    }
                } catch (\Exception $e) {
                    // create a dummy memory file with the error message
                    $dummy = fopen('php://memory', 'rw+');
                    fwrite($dummy, $e->getMessage());
                    rewind($dummy);

                    if (!$streamer->addFileFromStream($dummy, "{$name}_error.txt", [])) {
                        throw new \Exception('Failed to add file to zip');
                    }

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

            // Done
            $streamer->finalize();
        });
    }
}
