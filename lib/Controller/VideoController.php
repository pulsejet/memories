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
use OCA\Memories\Exif;
use OCA\Memories\Service\BinExt;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;

class VideoController extends GenericApiController
{
    /**
     * @NoAdminRequired
     *
     * @PublicPage
     *
     * @NoCSRFRequired
     *
     * Transcode a video to HLS by proxy
     */
    public function transcode(string $client, int $fileid, string $profile): Http\Response
    {
        return Util::guardEx(function () use ($client, $fileid, $profile) {
            // Make sure not running in read-only mode
            if (false !== $this->config->getSystemValue('memories.vod.disable', 'UNSET')) {
                throw Exceptions::Forbidden('Transcoding disabled');
            }

            // Check client identifier is 8 characters or more
            if (\strlen($client) < 8) {
                throw Exceptions::MissingParameter('client (invalid)');
            }

            // Get file
            $file = $this->fs->getUserFile($fileid);

            // Local files only for now
            if (!$file->getStorage()->isLocal()) {
                throw Exceptions::Forbidden('External storage not supported');
            }

            // Get file path
            $path = $file->getStorage()->getLocalFile($file->getInternalPath());
            if (!$path || !file_exists($path)) {
                throw Exceptions::NotFound('local file path');
            }

            // Check if file starts with temp dir
            $tmpDir = sys_get_temp_dir();
            if (0 === strpos($path, $tmpDir)) {
                throw Exceptions::Forbidden('files in temp directory not supported');
            }

            // Request and check data was received
            try {
                $status = $this->getUpstream($client, $path, $profile);
                if (409 === $status || -1 === $status) {
                    // Just a conflict (transcoding process changed)
                    return new JSONResponse(['message' => 'Conflict'], Http::STATUS_CONFLICT);
                }
                if (200 !== $status) {
                    throw new \Exception("Transcoder returned {$status}");
                }
            } catch (\Exception $e) {
                $msg = 'Transcode failed: '.$e->getMessage();
                $this->logger->error($msg, ['app' => 'memories']);

                throw $e;
            }

            // The response was already streamed, so we have nothing to do here
            exit;
        });
    }

    /**
     * @NoAdminRequired
     *
     * @PublicPage
     *
     * @NoCSRFRequired
     *
     * Return the live video part of a Live Photo
     */
    public function livephoto(
        int $fileid,
        string $liveid = '',
        string $format = '',
        string $transcode = ''
    ) {
        return Util::guardEx(function () use ($fileid, $liveid, $format, $transcode) {
            $file = $this->fs->getUserFile($fileid);

            // Check file liveid
            if (!$liveid) {
                throw Exceptions::MissingParameter('liveid');
            }

            // Response data
            $name = '';
            $mime = '';
            $blob = null;
            $liveVideoPath = null;

            // Video is inside the file
            $path = null;
            if (str_starts_with($liveid, 'self__')) {
                $path = $file->getStorage()->getLocalFile($file->getInternalPath());
                $mime = 'video/mp4';
                $name = $file->getName().'.mp4';
            }

            // Different manufacurers have different formats
            if ('self__trailer' === $liveid) {
                try { // Get trailer
                    $blob = Exif::getBinaryExifProp($path, '-trailer');
                } catch (\Exception $e) {
                    throw Exceptions::NotFound('file trailer');
                }
            } elseif ('self__embeddedvideo' === $liveid) {
                try { // Get embedded video file
                    $blob = Exif::getBinaryExifProp($path, '-EmbeddedVideoFile');
                } catch (\Exception $e) {
                    throw Exceptions::NotFound('embedded video');
                }
            } elseif (str_starts_with($liveid, 'self__traileroffset=')) {
                // Remove prefix
                $offset = (int) substr($liveid, \strlen('self__traileroffset='));
                if ($offset <= 0) {
                    throw Exceptions::BadRequest('Invalid offset');
                }

                // Read file from offset to end
                $blob = file_get_contents($path, false, null, $offset);
            } else {
                // Get stored video file (Apple MOV)
                $lp = $this->timelineQuery->getLivePhoto($fileid);
                if (!$lp || $lp['liveid'] !== $liveid) {
                    throw Exceptions::NotFound('live video entry');
                }

                // Get and return file
                $liveFileId = (int) $lp['fileid'];
                $files = $this->rootFolder->getById($liveFileId);
                if (0 === \count($files)) {
                    throw Exceptions::NotFound('live video file');
                }
                $liveFile = $files[0];

                if ($liveFile instanceof File) {
                    // Requested only JSON info
                    if ('json' === $format) {
                        return new JSONResponse($lp);
                    }

                    $name = $liveFile->getName();
                    $blob = $liveFile->getContent();
                    $mime = $liveFile->getMimeType();
                    $liveVideoPath = $liveFile->getStorage()->getLocalFile($liveFile->getInternalPath());
                }
            }

            // Data not found
            if (!$blob) {
                throw Exceptions::NotFound('live video data');
            }

            // Transcode video if allowed
            if ($transcode && !$this->config->getSystemValue('memories.vod.disable', true)) {
                try {
                    // If video path not given, write to temp file
                    if (!$liveVideoPath) {
                        $liveVideoPath = self::postFile($transcode, $blob)['path'];
                    }

                    // If this is H.264 it won't get transcoded anyway
                    if ($liveVideoPath && 200 === $this->getUpstream($transcode, $liveVideoPath, 'max.mov')) {
                        exit;
                    }
                } catch (\Exception $e) {
                    // Transcoding failed, just return the original video
                }
            }

            // Make and send response
            $response = new DataDisplayResponse($blob, Http::STATUS_OK, []);
            $response->setHeaders([
                'Content-Type' => $mime,
                'Content-Disposition' => "attachment; filename=\"{$name}\"",
            ]);
            $response->cacheFor(3600 * 24, false, false);

            return $response;
        });
    }

    private function getUpstream(string $client, string $path, string $profile)
    {
        $returnCode = $this->getUpstreamInternal($client, $path, $profile);

        // If status code was 0, it's likely the server is down
        // Make one attempt to start after killing whatever is there
        if (0 !== $returnCode && 503 !== $returnCode) {
            return $returnCode;
        }

        // Start goVod and get log file
        $logFile = BinExt::startGoVod();

        $returnCode = $this->getUpstreamInternal($client, $path, $profile);
        if (0 === $returnCode) {
            throw new \Exception("Transcoder could not be started, check {$logFile}");
        }

        return $returnCode;
    }

    private function getUpstreamInternal(string $client, string $path, string $profile)
    {
        // Make sure query params are repeated
        // For example, in folder sharing, we need the params on every request
        $url = BinExt::getGoVodUrl($client, $path, $profile);
        if ($params = $_SERVER['QUERY_STRING']) {
            $url .= "?{$params}";
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_HEADER, 0);

        // Catch connection abort here
        ignore_user_abort(true);

        // Stream the response to the browser without reading it into memory
        $headersWritten = false;
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) use (&$headersWritten, $profile) {
            $returnCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

            if (200 === $returnCode) {
                // Write headers if just got the first chunk of data
                if (!$headersWritten) {
                    $headersWritten = true;
                    $contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
                    header("Content-Type: {$contentType}");

                    if (str_ends_with($profile, 'mov')) {
                        // cache full video 24 hours
                        header('Cache-Control: max-age=86400, public');
                    } else {
                        // no caching of segments
                        header('Cache-Control: no-cache, no-store, must-revalidate');
                    }

                    http_response_code($returnCode);
                }

                echo $data;
                flush();

                if (connection_aborted()) {
                    return -1; // stop the transfer
                }
            }

            return \strlen($data);
        });

        // Start the request
        curl_exec($ch);
        $returnCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $returnCode;
    }

    /**
     * POST to go-vod to create a temporary file.
     *
     * @param mixed $blob
     */
    private static function postFile(string $client, $blob)
    {
        $url = BinExt::getGoVodUrl($client, '/create', 'ignore');

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $blob);

        $response = curl_exec($ch);
        $returnCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (200 !== $returnCode) {
            throw new \Exception("Could not create temporary file ({$returnCode})");
        }

        return json_decode($response, true);
    }
}
