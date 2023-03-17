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

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Exif;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;

class ImageController extends ApiBase
{
    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * Get preview of image
     */
    public function preview(
        int $id,
        int $x = 32,
        int $y = 32,
        bool $a = false,
        string $mode = 'fill'
    ) {
        if (-1 === $id || 0 === $x || 0 === $y) {
            return new JSONResponse([
                'message' => 'Invalid parameters',
            ], Http::STATUS_BAD_REQUEST);
        }

        $file = $this->getUserFile($id);
        if (!$file) {
            return new JSONResponse([
                'message' => 'File not found',
            ], Http::STATUS_NOT_FOUND);
        }

        try {
            $preview = \OC::$server->get(\OCP\IPreview::class)->getPreview($file, $x, $y, !$a, $mode);
            $response = new FileDisplayResponse($preview, Http::STATUS_OK, [
                'Content-Type' => $preview->getMimeType(),
            ]);
            $response->cacheFor(3600 * 24, false, true);

            return $response;
        } catch (\OCP\Files\NotFoundException $e) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse([], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * Get preview of many images
     */
    public function multipreview()
    {
        // read body to array
        try {
            $body = file_get_contents('php://input');
            $files = json_decode($body, true);
        } catch (\Exception $e) {
            return new JSONResponse([], Http::STATUS_BAD_REQUEST);
        }

        /** @var \OCP\IPreview $previewManager */
        $previewManager = \OC::$server->get(\OCP\IPreview::class);

        // For checking max previews
        $previewRoot = new \OC\Preview\Storage\Root(
            \OC::$server->get(IRootFolder::class),
            \OC::$server->get(\OC\SystemConfig::class),
        );

        // stream the response
        header('Content-Type: application/octet-stream');
        header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + 7 * 3600 * 24));
        header('Cache-Control: max-age='. 7 * 3600 * 24 .', private');

        foreach ($files as $bodyFile) {
            if (!isset($bodyFile['reqid']) || !isset($bodyFile['fileid']) || !isset($bodyFile['x']) || !isset($bodyFile['y']) || !isset($bodyFile['a'])) {
                continue;
            }
            $reqid = $bodyFile['reqid'];
            $fileid = (int) $bodyFile['fileid'];
            $x = (int) $bodyFile['x'];
            $y = (int) $bodyFile['y'];
            $a = '1' === $bodyFile['a'];
            if ($fileid <= 0 || $x <= 0 || $y <= 0) {
                continue;
            }

            $file = $this->getUserFile($fileid);
            if (!$file) {
                continue;
            }

            try {
                // Make sure max preview exists
                $fileId = (string) $file->getId();
                $folder = $previewRoot->getFolder($fileId);
                $hasMax = false;
                foreach ($folder->getDirectoryListing() as $preview) {
                    $name = $preview->getName();
                    if (str_contains($name, '-max')) {
                        $hasMax = true;

                        break;
                    }
                }
                if (!$hasMax) {
                    continue;
                }

                // Add this preview to the response
                $preview = $previewManager->getPreview($file, $x, $y, !$a, \OCP\IPreview::MODE_FILL);
                $content = $preview->getContent();
                if (empty($content)) {
                    continue;
                }

                ob_start();
                echo json_encode([
                    'reqid' => $reqid,
                    'Content-Length' => \strlen($content),
                    'Content-Type' => $preview->getMimeType(),
                ]);
                echo "\n";
                echo $content;
                ob_end_flush();
            } catch (\OCP\Files\NotFoundException $e) {
                continue;
            } catch (\Exception $e) {
                continue;
            }
        }

        exit;
    }

    /**
     * @NoAdminRequired
     *
     * @PublicPage
     *
     * Get EXIF info for an image with file id
     *
     * @param string fileid
     */
    public function info(
        string $id,
        bool $basic = false,
        bool $current = false,
        bool $tags = false
    ): JSONResponse {
        $file = $this->getUserFile((int) $id);
        if (!$file) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Get the image info
        $info = $this->timelineQuery->getInfoById($file->getId(), $basic);

        // Allow these ony for logged in users
        if (null !== $this->userSession->getUser()) {
            // Get list of tags for this file
            if ($tags) {
                $info['tags'] = $this->getTags($file->getId());
            }

            // Get latest exif data if requested
            if ($current) {
                $info['current'] = Exif::getExifFromFile($file);
            }
        }

        // Inject permissions and convert to string
        $info['permissions'] = \OCA\Memories\Util::permissionsToStr($file->getPermissions());

        // Inject other file parameters that are cheap to get now
        $info['mimetype'] = $file->getMimeType();
        $info['size'] = $file->getSize();
        $info['basename'] = $file->getName();

        return new JSONResponse($info, Http::STATUS_OK);
    }

    /**
     * @NoAdminRequired
     *
     * Set the exif data for a file.
     *
     * @param string fileid
     * @param array  raw exif data
     */
    public function setExif(string $id, array $raw): JSONResponse
    {
        $file = $this->getUserFile((int) $id);
        if (!$file) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Check if user has permissions
        if (!$file->isUpdateable()) {
            return new JSONResponse([], Http::STATUS_FORBIDDEN);
        }

        // Check for end-to-end encryption
        if (\OCA\Memories\Util::isEncryptionEnabled()) {
            return new JSONResponse(['message' => 'Cannot change encrypted file'], Http::STATUS_PRECONDITION_FAILED);
        }

        // Check if allowed to edit file
        $mime = $file->getMimeType();
        if (!\in_array($mime, Exif::allowedEditMimetypes(), true)) {
            $name = $file->getName();

            return new JSONResponse(['message' => "Cannot edit file {$name} (blacklisted type {$mime})"], Http::STATUS_PRECONDITION_FAILED);
        }

        // Get original file from body
        $path = $file->getStorage()->getLocalFile($file->getInternalPath());

        try {
            Exif::setExif($path, $raw);
        } catch (\Exception $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // Update remote file if not local
        if (!$file->getStorage()->isLocal()) {
            $file->putContent(fopen($path, 'r')); // closes the handler
        }

        // Touch the file, triggering a reprocess through the hook
        $file->touch();

        return new JSONResponse([], Http::STATUS_OK);
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * Get a full resolution decodable image for editing from a file.
     * The returned image may be png / webp / jpeg / gif.
     * These formats are supported by all browsers.
     */
    public function decodable(string $id)
    {
        $file = $this->getUserFile((int) $id);
        if (!$file) {
            return new JSONResponse([], Http::STATUS_NOT_FOUND);
        }

        // Check if valid image
        $mimetype = $file->getMimeType();
        if (!\in_array($mimetype, Application::IMAGE_MIMES, true)) {
            return new JSONResponse([], Http::STATUS_FORBIDDEN);
        }

        /** @var string Blob of image */
        $blob = $file->getContent();

        // Convert image to JPEG if required
        if (!\in_array($mimetype, ['image/png', 'image/webp', 'image/jpeg', 'image/gif'], true)) {
            $image = new \Imagick();
            $image->readImageBlob($blob);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(95);
            $blob = $image->getImageBlob();
            $mimetype = $image->getImageMimeType();
        }

        // Return the image
        $response = new Http\DataDisplayResponse($blob, Http::STATUS_OK, ['Content-Type' => $mimetype]);
        $response->cacheFor(3600 * 24, false, false);

        return $response;
    }

    /**
     * Get the tags for a file.
     */
    private function getTags(int $fileId): array
    {
        // Make sure tags are enabled
        if (!\OCA\Memories\Util::tagsIsEnabled($this->appManager)) {
            return [];
        }

        // Get the tag ids for this file
        $objectMapper = \OC::$server->get(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $tagIds = $objectMapper->getTagIdsForObjects([$fileId], 'files')[(string) $fileId];

        // Get the tag names and filter out the ones that are not user visible
        $tagManager = \OC::$server->get(\OCP\SystemTag\ISystemTagManager::class);

        /** @var \OCP\SystemTag\ISystemTag[] */
        $tags = $tagManager->getTagsByIds($tagIds);

        return array_map(function ($tag) {
            return $tag->getName();
        }, array_filter($tags, function ($tag) {
            return $tag->isUserVisible();
        }));
    }
}
