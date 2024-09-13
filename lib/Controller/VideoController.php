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
use OCA\Memories\HttpResponseException;
use OCA\Memories\Service\BinExt;
use OCA\Memories\Settings\SystemConfig;
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
            // Make sure transcoding is enabled
            if (SystemConfig::get('memories.vod.disable')) {
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
            if (str_starts_with($path, $tmpDir)) {
                throw Exceptions::Forbidden('files in temp directory not supported');
            }

            // Request and check data was received
            return Util::guardExDirect(function (Http\IOutput $out) use ($client, $path, $profile) {
                try {
                    $status = $this->getUpstream($client, $path, $profile);
                    if (409 === $status || -1 === $status) {
                        // Just a conflict (transcoding process changed)
                        $response = new JSONResponse(['message' => 'Conflict'], Http::STATUS_CONFLICT);

                        throw new HttpResponseException($response);
                    }
                    if (200 !== $status) {
                        throw new \Exception("Transcoder returned {$status}");
                    }
                } catch (\Exception $e) {
                    if ($e instanceof HttpResponseException && Http::STATUS_CONFLICT === $e->response->getStatus()) {
                        throw $e; // Logging this is noise
                    }

                    // We cannot show this error in the user interface, so log it
                    $this->logger->error('Transcode failed: '.$e->getMessage(), ['app' => 'memories']);

                    throw $e;
                }
            });
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
        string $transcode = '',
    ): Http\Response {
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
            $path = '<>';
            if (str_starts_with($liveid, 'self__')) {
                $path = $file->getStorage()->getLocalFile($file->getInternalPath())
                    ?: throw Exceptions::BadRequest('[Video] File path missing (self__*)');
                $mime = 'video/mp4';
                $name = $file->getName().'.mp4';
            }

            // Different manufacurers have different formats
            if ('self__trailer' === $liveid) {
                try { // Get trailer
                    $blob = Exif::getBinaryExifProp($path, '-trailer');
                } catch (\Exception) {
                    throw Exceptions::NotFound('file trailer');
                }
            } elseif (str_starts_with($liveid, 'self__exifbin=')) {
                $field = substr($liveid, \strlen('self__exifbin='));

                // Need explicit whitelisting here because this is user input
                if (!\in_array($field, ['EmbeddedVideoFile', 'MotionPhotoVideo'], true)) {
                    throw Exceptions::BadRequest('Invalid binary EXIF field');
                }

                try { // Get embedded video file
                    $blob = Exif::getBinaryExifProp($path, "-{$field}");
                } catch (\Exception) {
                    throw Exceptions::NotFound('Could not read binary EXIF field');
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
                $liveFile = $this->getClosestLiveVideo($file);
                if (null === $liveFile) {
                    throw Exceptions::NotFound('live video file');
                }

                // Requested only JSON info
                if ('json' === $format) {
                    // IPhoto object for the live video
                    return new JSONResponse([
                        'fileid' => $liveFile->getId(),
                        'etag' => $liveFile->getEtag(),
                        'basename' => $liveFile->getName(),
                        'mimetype' => $liveFile->getMimeType(),
                    ]);
                }

                $name = $liveFile->getName();
                $blob = $liveFile->getContent();
                $mime = $liveFile->getMimeType();
                $liveVideoPath = $liveFile->getStorage()->getLocalFile($liveFile->getInternalPath());
            }

            // Data not found
            if (!$blob) {
                throw Exceptions::NotFound('live video data');
            }

            // Cannot return JSON if it is not a file
            if ('json' === $format) {
                throw Exceptions::BadRequest('Invalid format');
            }

            // Transcode video if allowed
            if ($transcode && !SystemConfig::get('memories.vod.disable')) {
                // If video path not given, write to temp file
                if (!$liveVideoPath) {
                    $liveVideoPath = self::postFile($transcode, $blob)['path'];
                }

                // If this is H.264 it won't get transcoded anyway
                if ($liveVideoPath) {
                    return Util::guardExDirect(function ($out) use ($transcode, $liveVideoPath) {
                        $this->getUpstream($transcode, $liveVideoPath, 'max.mp4');
                    });
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

    private function getUpstream(string $client, string $path, string $profile): int
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

    private function getUpstreamInternal(string $client, string $path, string $profile): int
    {
        // Make sure query params are repeated
        // For example, in folder sharing, we need the params on every request
        $url = BinExt::getGoVodUrl($client, $path, $profile);
        if (\array_key_exists('QUERY_STRING', $_SERVER) && !empty($params = $_SERVER['QUERY_STRING'])) {
            $url .= "?{$params}";
        }

        // Initialize request
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_HEADER, 0);

        // Add header for expected go-vod version
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Go-Vod-Version: '.BinExt::GOVOD_VER]);

        // Catch connection abort here
        ignore_user_abort(true);

        $headerswritten = false;
        $sendheaders = static function (\CurlHandle $curl) use (&$headerswritten, $profile): void {
            // Write headers if just got the first chunk of data
            if ($headerswritten) {
                return;
            }
            $headerswritten = true;

            // Pass ahead response code
            http_response_code((int) curl_getinfo($curl, CURLINFO_HTTP_CODE));

            // Pass ahead content type
            $contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
            header("Content-Type: {$contentType}");

            // Caching headers
            if (str_ends_with($profile, 'mp4')) {
                // cache full video 24 hours
                header('Cache-Control: max-age=86400, public');
            } else {
                // no caching of segments
                header('Cache-Control: no-cache, no-store, must-revalidate');
            }
        };

        // On Safari with MP4, chunked transfer encoding is not supported
        // So we need to read the whole file into memory and send it
        $userAgent = $this->request->getHeader('User-Agent');
        $isSafari = preg_match('/^((?!chrome|android).)*safari/i', $userAgent);

        if (!$isSafari) {
            // Stream the response to the browser without reading it into memory
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function (\CurlHandle $curl, string $data) use (&$sendheaders) {
                $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

                if (200 === $code) {
                    // Write headers if not done yet
                    $sendheaders($curl);

                    // Chunked transfer encoding
                    echo $data;
                    flush();

                    // Check if the client is still connected
                    if (connection_aborted()) {
                        return -1; // stop the transfer
                    }
                }

                return \strlen($data);
            });
        }

        // Start the request
        $response = curl_exec($ch);

        // Send the entire response if Safari
        if ($isSafari && \is_string($response)) {
            $sendheaders($ch);
            header('Content-Length: '.\strlen($response)); // critical
            echo $response;
        }

        $returnCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $returnCode;
    }

    /**
     * POST to go-vod to create a temporary file.
     *
     * @return mixed The response from upstream
     */
    private static function postFile(string $client, string $blob): mixed
    {
        try {
            return self::postFileInternal($client, $blob);
        } catch (\Exception $e) {
            if (BinExt::startGoVod()) { // If the server is down, try to start it
                return self::postFileInternal($client, $blob);
            }

            throw $e;
        }
    }

    private static function postFileInternal(string $client, string $blob): mixed
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

        return json_decode((string) $response, true);
    }

    /**
     * Get the closest live video to the given file.
     */
    private function getClosestLiveVideo(File $file): ?File
    {
        // Get stored video file (Apple MOV)
        $liveRecords = $this->tq->getLivePhotos($file->getId());

        // Get file paths for all live photos
        $liveFiles = array_map(fn ($r) => $this->rootFolder->getById((int) $r['fileid']), $liveRecords);
        $liveFiles = array_filter($liveFiles, static fn ($files) => \count($files) > 0 && $files[0] instanceof File);

        /** @var File[] (checked above) */
        $liveFiles = array_map(static fn ($files) => $files[0], $liveFiles);

        // Should be filtered enough by now
        if (!\count($liveFiles)) {
            return null;
        }

        // All paths including the image and videos need to be processed
        $paths = array_map(static function (File $file) {
            $path = $file->getPath();
            $filename = strtolower($file->getName());

            // Remove extension so the filename itself counts in the path
            if (str_contains($filename, '.')) {
                $filename = substr($filename, 0, strrpos($filename, '.') ?: null);
            }

            // Get components with the filename as lowercase
            $components = explode('/', $path);
            if (($l = \count($components)) > 0) {
                $components[$l - 1] = $filename;
            }

            return $components;
        }, array_merge($liveFiles, [$file]));

        // Find closest path match
        $imagePath = array_pop($paths);
        $scores = array_map(static function ($path) use ($imagePath) {
            $score = 0;
            $length = min(\count($path), \count($imagePath));
            for ($i = 0; $i < $length; ++$i) {
                if ($path[$i] === $imagePath[$i]) {
                    $score += 10000; // Exact match bonus
                } else {
                    $score -= \count($path) - $i; // Walk down penalty

                    break;
                }
            }

            return $score;
        }, $paths);

        // Sort by score
        array_multisort($scores, SORT_ASC, $liveFiles);

        return array_pop($liveFiles);
    }
}
