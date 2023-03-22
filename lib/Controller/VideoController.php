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

use OCA\Memories\Errors;
use OCA\Memories\Exif;
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
        // Make sure not running in read-only mode
        if (false !== $this->config->getSystemValue('memories.vod.disable', 'UNSET')) {
            return Errors::Forbidden('Transcoding disabled');
        }

        // Check client identifier is 8 characters or more
        if (\strlen($client) < 8) {
            return Errors::MissingParameter('client (invalid)');
        }

        // Get file
        $file = $this->getUserFile($fileid);
        if (!$file || !$file->isReadable()) {
            return Errors::NotFoundFile($fileid);
        }

        // Local files only for now
        if (!$file->getStorage()->isLocal()) {
            return Errors::Forbidden('External storage not supported');
        }

        // Get file path
        $path = $file->getStorage()->getLocalFile($file->getInternalPath());
        if (!$path || !file_exists($path)) {
            return Errors::NotFound('local file path');
        }

        // Check if file starts with temp dir
        $tmpDir = sys_get_temp_dir();
        if (0 === strpos($path, $tmpDir)) {
            return Errors::Forbidden('files in temp directory not supported');
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

            return Errors::Generic($e);
        }

        // The response was already streamed, so we have nothing to do here
        exit;
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
        $file = $this->getUserFile($fileid);
        if (null === $file) {
            return Errors::NotFoundFile($fileid);
        }

        // Check file liveid
        if (!$liveid) {
            return Errors::MissingParameter('liveid');
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
                return Errors::NotFound('file trailer');
            }
        } elseif ('self__embeddedvideo' === $liveid) {
            try { // Get embedded video file
                $blob = Exif::getBinaryExifProp($path, '-EmbeddedVideoFile');
            } catch (\Exception $e) {
                return Errors::NotFound('embedded video');
            }
        } elseif (str_starts_with($liveid, 'self__traileroffset=')) {
            // Remove prefix
            $offset = (int) substr($liveid, \strlen('self__traileroffset='));
            if ($offset <= 0) {
                return new JSONResponse(['message' => 'Invalid offset'], Http::STATUS_BAD_REQUEST);
            }

            // Read file from offset to end
            $blob = file_get_contents($path, false, null, $offset);
        } else {
            // Get stored video file (Apple MOV)
            $lp = $this->timelineQuery->getLivePhoto($fileid);
            if (!$lp || $lp['liveid'] !== $liveid) {
                return Errors::NotFound('live video entry');
            }

            // Get and return file
            $liveFileId = (int) $lp['fileid'];
            $files = $this->rootFolder->getById($liveFileId);
            if (0 === \count($files)) {
                return Errors::NotFound('live video file');
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
            return Errors::NotFound('live video data');
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
    }

    /**
     * Start the transcoder.
     *
     * @return string Path to log file
     */
    public static function startGoVod()
    {
        $config = \OC::$server->get(\OCP\IConfig::class);

        // Get transcoder path
        $transcoder = $config->getSystemValue('memories.vod.path', false);
        if (!$transcoder) {
            throw new \Exception('Transcoder not configured');
        }

        // Make sure transcoder exists
        if (!file_exists($transcoder)) {
            throw new \Exception("Transcoder not found; run occ memories video-setup! ({$transcoder})");
        }

        // Make transcoder executable
        if (!is_executable($transcoder)) {
            @chmod($transcoder, 0755);
            if (!is_executable($transcoder)) {
                throw new \Exception("Transcoder not executable (chmod 755 {$transcoder})");
            }
        }

        // Kill the transcoder in case it's running
        \OCA\Memories\Util::pkill($transcoder);

        // Start transcoder
        [$configFile, $logFile] = self::makeGoVodConfig($config);
        shell_exec("nohup {$transcoder} {$configFile} >> '{$logFile}' 2>&1 & > /dev/null");

        // wait for 1s
        sleep(1);

        return $logFile;
    }

    /**
     * Get the upstream URL for a video.
     */
    public static function getGoVodUrl(string $client, string $path, string $profile): string
    {
        $config = \OC::$server->get(\OCP\IConfig::class);
        $path = rawurlencode($path);

        $bind = $config->getSystemValue('memories.vod.bind', '127.0.0.1:47788');
        $connect = $config->getSystemValue('memories.vod.connect', $bind);

        return "http://{$connect}/{$client}{$path}/{$profile}";
    }

    /**
     * Get the goVod config JSON.
     */
    public static function getGoVodConfig(\OCP\IConfig $config): array
    {
        // Migrate legacy config: remove in 2024
        self::migrateLegacyConfig($config);

        // Get temp directory
        $defaultTmp = sys_get_temp_dir().'/go-vod/';
        $tmpPath = $config->getSystemValue('memories.vod.tempdir', $defaultTmp);

        // Make sure path ends with slash
        if ('/' !== substr($tmpPath, -1)) {
            $tmpPath .= '/';
        }

        // Add instance ID to path
        $tmpPath .= $config->getSystemValue('instanceid', 'default');

        // (Re-)create temp dir
        shell_exec("rm -rf '{$tmpPath}' && mkdir -p '{$tmpPath}' && chmod 755 '{$tmpPath}'");

        // Check temp directory exists
        if (!is_dir($tmpPath)) {
            throw new \Exception("Temp directory could not be created ({$tmpPath})");
        }

        // Check temp directory is writable
        if (!is_writable($tmpPath)) {
            throw new \Exception("Temp directory is not writable ({$tmpPath})");
        }

        // Get config from system values
        return [
            'bind' => $config->getSystemValue('memories.vod.bind', '127.0.0.1:47788'),
            'ffmpeg' => $config->getSystemValue('memories.vod.ffmpeg', 'ffmpeg'),
            'ffprobe' => $config->getSystemValue('memories.vod.ffprobe', 'ffprobe'),
            'tempdir' => $tmpPath,

            'vaapi' => $config->getSystemValue('memories.vod.vaapi', false),
            'vaapiLowPower' => $config->getSystemValue('memories.vod.vaapi.low_power', false),

            'nvenc' => $config->getSystemValue('memories.vod.nvenc', false),
            'nvencTemporalAQ' => $config->getSystemValue('memories.vod.nvenc.temporal_aq', false),
            'nvencScale' => $config->getSystemValue('memories.vod.nvenc.scale', 'npp'),
        ];
    }

    private function getUpstream(string $client, string $path, string $profile)
    {
        $returnCode = $this->getUpstreamInternal($client, $path, $profile);
        $isExternal = $this->config->getSystemValue('memories.vod.external', false);

        // If status code was 0, it's likely the server is down
        // Make one attempt to start after killing whatever is there
        if (0 !== $returnCode || $isExternal) {
            return $returnCode;
        }

        // Start goVod and get log file
        $logFile = self::startGoVod();

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
        $url = self::getGoVodUrl($client, $path, $profile);
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
        $url = self::getGoVodUrl($client, '/create', 'ignore');

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

    /**
     * Construct the goVod config JSON and put it to a file.
     *
     * @return array [config file, log file]
     */
    private static function makeGoVodConfig(\OCP\IConfig $config): array
    {
        $env = self::getGoVodConfig($config);
        $tmpPath = $env['tempdir'];

        // Write config to file
        $logFile = $tmpPath.'.log';
        $configFile = $tmpPath.'.json';
        file_put_contents($configFile, json_encode($env, JSON_PRETTY_PRINT));

        // Log file is not in config
        // go-vod just writes to stdout/stderr
        return [$configFile, $logFile];
    }

    /**
     * Migrate legacy config to new.
     *
     * Remove in year 2024
     */
    private static function migrateLegacyConfig(\OCP\IConfig $config)
    {
        if (null === $config->getSystemValue('memories.no_transcode', null)) {
            return;
        }

        // Mapping
        $legacyConfig = [
            'memories.no_transcode' => 'memories.vod.disable',
            'memories.transcoder' => 'memories.vod.path',
            'memories.ffmpeg_path' => 'memories.vod.ffmpeg',
            'memories.ffprobe_path' => 'memories.vod.ffprobe',
            'memories.qsv' => 'memories.vod.vaapi',
            'memories.nvenc' => 'memories.vod.nvenc',
            'memories.tmp_path' => 'memories.vod.tempdir',
        ];

        foreach ($legacyConfig as $old => $new) {
            if (null !== $config->getSystemValue($old, null)) {
                $config->setSystemValue($new, $config->getSystemValue($old));
                $config->deleteSystemValue($old);
            }
        }

        // Migrate bind address
        if ($port = null !== $config->getSystemValue('memories.govod_port', null)) {
            $config->setSystemValue('memories.vod.bind', "127.0.0.1:{$port}");
            $config->deleteSystemValue('memories.govod_port');
        }
    }
}
