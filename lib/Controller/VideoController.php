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
     * @param string fileid
     * @param string video profile
     *
     * @return JSONResponse an empty JSONResponse with respective http status code
     */
    public function transcode(string $fileid, string $profile): Http\Response
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
        }

        // Make sure not running in read-only mode
        if ($this->config->getSystemValue('memories.no_transcode', false)) {
            return new JSONResponse(['message' => 'Transcoding disabled'], Http::STATUS_FORBIDDEN);
        }

        // Get file
        $files = $this->rootFolder->getUserFolder($user->getUID())->getById($fileid);
        if (0 === \count($files)) {
            return new JSONResponse(['message' => 'File not found'], Http::STATUS_NOT_FOUND);
        }
        $file = $files[0];

        // Local files only for now
        if (!$file->getStorage()->isLocal()) {
            return new JSONResponse(['message' => 'External storage not supported'], Http::STATUS_FORBIDDEN);
        }

        // Get file path
        $path = $file->getStorage()->getLocalFile($file->getInternalPath());
        if (!$path || !file_exists($path)) {
            return new JSONResponse(['message' => 'File not found'], Http::STATUS_NOT_FOUND);
        }

        // Make upstream request
        $ch = curl_init("http://localhost:9999/vod/{$path}/{$profile}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        $data = curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $returnCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Check data was received
        if ($returnCode >= 400 || false === $data) {
            return new JSONResponse(['message' => 'Transcode failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // Create and send response
        $response = new DataDisplayResponse($data, Http::STATUS_OK, [
            'Content-Type' => $contentType,
        ]);
        $response->cacheFor(3600 * 24, false, false);

        return $response;
    }
}
