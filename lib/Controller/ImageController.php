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
use OCA\Memories\Exceptions;
use OCA\Memories\Exif;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;

class ImageController extends GenericApiController
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
        return Util::guardEx(function () use ($id, $x, $y, $a, $mode) {
            if (-1 === $id || 0 === $x || 0 === $y) {
                throw Exceptions::MissingParameter('id, x, y');
            }

            $file = $this->fs->getUserFile($id);
            $preview = \OC::$server->get(\OCP\IPreview::class)->getPreview($file, $x, $y, !$a, $mode);
            $response = new FileDisplayResponse($preview, Http::STATUS_OK, [
                'Content-Type' => $preview->getMimeType(),
            ]);
            $response->cacheFor(3600 * 24, false, true);

            return $response;
        });
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
        return Util::guardEx(function () {
            // read body to array
            $body = file_get_contents('php://input');
            $files = json_decode($body, true);

            // Filter files with valid parameters
            $files = array_filter($files, function ($file) {
                return isset($file['reqid'], $file['fileid'], $file['x'], $file['y'], $file['a'])
                    && (int) $file['fileid'] > 0
                    && (int) $file['x'] > 0
                    && (int) $file['y'] > 0;
            });

            // Sort files by size, ascending
            usort($files, function ($a, $b) {
                $aArea = (int) $a['x'] * (int) $a['y'];
                $bArea = (int) $b['x'] * (int) $b['y'];

                return $aArea <=> $bArea;
            });

            /** @var \OCP\IPreview $previewManager */
            $previewManager = \OC::$server->get(\OCP\IPreview::class);

            // For checking max previews
            $previewRoot = new \OC\Preview\Storage\Root(
                \OC::$server->get(IRootFolder::class),
                \OC::$server->get(\OC\SystemConfig::class),
            );

            // stream the response
            header('Content-Type: application/octet-stream');

            foreach ($files as $bodyFile) {
                $reqid = $bodyFile['reqid'];
                $fileid = (int) $bodyFile['fileid'];
                $x = (int) $bodyFile['x'];
                $y = (int) $bodyFile['y'];
                $a = '1' === $bodyFile['a'];

                try {
                    // Make sure max preview exists
                    $file = $this->fs->getUserFile($fileid);
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
                    // Encode parameters
                    $json = json_encode([
                        'reqid' => $reqid,
                        'len' => \strlen($content),
                        'type' => $preview->getMimeType(),
                    ]);

                    // Send the length of the json as a single byte
                    echo \chr(\strlen($json));
                    echo $json;

                    // Send the image
                    echo $content;
                    ob_end_flush();
                } catch (\OCP\Files\NotFoundException $e) {
                    continue;
                } catch (\Exception $e) {
                    continue;
                }
            }

            exit;
        });
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
    ): Http\Response {
        return Util::guardEx(function () use ($id, $basic, $current, $tags) {
            $file = $this->fs->getUserFile((int) $id);

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
        });
    }

    /**
     * @NoAdminRequired
     *
     * Set the exif data for a file.
     *
     * @param string fileid
     * @param array  raw exif data
     */
    public function setExif(string $id, array $raw): Http\Response
    {
        return Util::guardEx(function () use ($id, $raw) {
            $file = $this->fs->getUserFile((int) $id);

            // Check if user has permissions
            if (!$file->isUpdateable() || Util::isEncryptionEnabled()) {
                throw Exceptions::ForbiddenFileUpdate($file->getName());
            }

            // Check if allowed to edit file
            $mime = $file->getMimeType();
            if (!\in_array($mime, Exif::allowedEditMimetypes(), true)) {
                $name = $file->getName();

                throw Exceptions::Forbidden("Cannot edit file {$name} (blacklisted type {$mime})");
            }

            // Set the exif data
            Exif::setFileExif($file, $raw);

            return new JSONResponse([], Http::STATUS_OK);
        });
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
    public function decodable(string $id): Http\Response
    {
        return Util::guardEx(function () use ($id) {
            $file = $this->fs->getUserFile((int) $id);

            // Check if valid image
            $mimetype = $file->getMimeType();
            if (!\in_array($mimetype, Application::IMAGE_MIMES, true)) {
                throw Exceptions::Forbidden('Not an image');
            }

            /** @var string Blob of image */
            $blob = $file->getContent();

            // Convert image to JPEG if required
            if (!\in_array($mimetype, ['image/png', 'image/webp', 'image/jpeg', 'image/gif'], true)) {
                [$blob, $mimetype] = $this->getImageJPEG($blob, $mimetype);
            }

            // Return the image
            $response = new Http\DataDisplayResponse($blob, Http::STATUS_OK, ['Content-Type' => $mimetype]);
            $response->cacheFor(3600 * 24, false, false);

            return $response;
        });
    }

    /**
     * Given a blob of image data, return a JPEG blob.
     *
     * @param string $blob     Blob of image data in any format
     * @param string $mimetype Mimetype of image data
     *
     * @return array [blob, mimetype]
     */
    private function getImageJPEG($blob, $mimetype): array
    {
        // TODO: Use imaginary if available

        // Check if Imagick is available
        if (!class_exists('Imagick')) {
            throw Exceptions::Forbidden('Imagick extension is not available');
        }

        // Read original image
        try {
            $image = new \Imagick();
            $image->readImageBlob($blob);
        } catch (\ImagickException $e) {
            throw Exceptions::Forbidden('Imagick failed to read image: '.$e->getMessage());
        }

        // Convert to JPEG
        try {
            $image->autoOrient();
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(95);
            $blob = $image->getImageBlob();
            $mimetype = $image->getImageMimeType();
        } catch (\ImagickException $e) {
            throw Exceptions::Forbidden('Imagick failed to convert image: '.$e->getMessage());
        }

        return [$blob, $mimetype];
    }

    /**
     * Get the tags for a file.
     */
    private function getTags(int $fileId): array
    {
        // Make sure tags are enabled
        if (!Util::tagsIsEnabled()) {
            return [];
        }

        // Get the tag ids for this file
        $objectMapper = \OC::$server->get(\OCP\SystemTag\ISystemTagObjectMapper::class);
        $tagIds = $objectMapper->getTagIdsForObjects([$fileId], 'files')[(string) $fileId];

        // Get the tag names and filter out the ones that are not user visible
        $tagManager = \OC::$server->get(\OCP\SystemTag\ISystemTagManager::class);

        /** @var \OCP\SystemTag\ISystemTag[] */
        $tags = $tagManager->getTagsByIds($tagIds);

        $visible = array_filter($tags, fn ($t) => $t->isUserVisible());

        // Get the tag names
        return array_map(fn ($t) => $t->getName(), $visible);
    }
}
