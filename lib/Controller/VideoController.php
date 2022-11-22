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

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;

class VideoController extends ApiBase
{
    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * Transcode a video to HLS by proxy
     *
     * @return JSONResponse an empty JSONResponse with respective http status code
     */
    public function transcode(string $client, string $fileid, string $profile): Http\Response
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // Make sure not running in read-only mode
        if (false !== $this->config->getSystemValue('memories.no_transcode', 'UNSET')) {
            return new JSONResponse(['message' => 'Transcoding disabled'], Http::STATUS_FORBIDDEN);
        }

        // Check client identifier is 8 characters or more
        if (\strlen($client) < 8) {
            return new JSONResponse(['message' => 'Invalid client identifier'], Http::STATUS_BAD_REQUEST);
        }

        // Get file
        $files = $this->rootFolder->getUserFolder($user->getUID())->getById($fileid);
        if (0 === \count($files)) {
            return new JSONResponse(['message' => 'File not found'], Http::STATUS_NOT_FOUND);
        }
        $file = $files[0];

        if (!($file->getPermissions() & \OCP\Constants::PERMISSION_READ)) {
            return new JSONResponse(['message' => 'File not readable'], Http::STATUS_FORBIDDEN);
        }

        // Local files only for now
        if (!$file->getStorage()->isLocal()) {
            return new JSONResponse(['message' => 'External storage not supported'], Http::STATUS_FORBIDDEN);
        }

        // Get file path
        $path = $file->getStorage()->getLocalFile($file->getInternalPath());
        if (!$path || !file_exists($path)) {
            return new JSONResponse(['message' => 'File not found'], Http::STATUS_NOT_FOUND);
        }

        // Check if file starts with temp dir
        $tmpDir = sys_get_temp_dir();
        if (0 === strpos($path, $tmpDir)) {
            return new JSONResponse(['message' => 'File is in temp dir!'], Http::STATUS_NOT_FOUND);
        }

        // Make upstream request
        [$data, $contentType, $returnCode] = $this->getUpstream($client, $path, $profile);

        // If status code was 0, it's likely the server is down
        // Make one attempt to start if we can't find the process
        if (0 === $returnCode) {
            $transcoder = $this->config->getSystemValue('memories.transcoder', false);
            if (!$transcoder) {
                return new JSONResponse(['message' => 'Transcoder not configured'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            // Make transcoder executable
            if (!is_executable($transcoder)) {
                chmod($transcoder, 0755);
            }

            // Check for environment variables
            $env = '';

            // QSV with VAAPI
            $vaapi = $this->config->getSystemValue('memories.qsv', false);
            if ($vaapi) {
                $env .= 'VAAPI=1 ';
            }

            // Paths
            $ffmpegPath = $this->config->getSystemValue('memories.ffmpeg_path', 'ffmpeg');
            $ffprobePath = $this->config->getSystemValue('memories.ffprobe_path', 'ffprobe');
            $tmpPath = $this->config->getSystemValue('memories.tmp_path', sys_get_temp_dir());
            $env .= "FFMPEG='{$ffmpegPath}' FFPROBE='{$ffprobePath}' GOVOD_TEMPDIR='{$tmpPath}/go-vod' ";

            // Check if already running
            exec("pkill {$transcoder}");
            shell_exec("{$env} nohup {$transcoder} > {$tmpPath}/go-vod.log 2>&1 & > /dev/null");

            // wait for 1s and try again
            sleep(1);
            [$data, $contentType, $returnCode] = $this->getUpstream($client, $path, $profile);
        }

        // Check data was received
        if ($returnCode >= 400 || false === $data) {
            return new JSONResponse(['message' => 'Transcode failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // Create and send response
        $response = new DataDisplayResponse($data, Http::STATUS_OK, [
            'Content-Type' => $contentType,
        ]);
        $response->cacheFor(0, false, false);

        return $response;
    }

    private function getUpstream($client, $path, $profile)
    {
        $path = rawurlencode($path);
        $ch = curl_init("http://127.0.0.1:47788/{$client}{$path}/{$profile}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        $data = curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $returnCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$data, $contentType, $returnCode];
    }
}
