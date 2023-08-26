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
use OCA\Memories\Service;
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
    public function multipreview(): Http\Response
    {
        return Util::guardExDirect(function (Http\IOutput $out) {
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
            $out->setHeader('Content-Type: application/octet-stream');

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
                    $out->setOutput(\chr(\strlen($json)));
                    $out->setOutput($json);

                    // Send the image
                    $out->setOutput($content);
                    ob_end_flush();
                } catch (\OCP\Files\NotFoundException $e) {
                    continue;
                } catch (\Exception $e) {
                    continue;
                }
            }
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
        int $id,
        bool $basic = false,
        bool $current = false,
        bool $tags = false,
        string $clusters = ''
    ): Http\Response {
        return Util::guardEx(function () use ($id, $basic, $current, $tags, $clusters) {
            $file = $this->fs->getUserFile($id);

            // Get the image info
            $info = $this->timelineQuery->getInfoById($id, $basic);

            // Add fileid and etag
            $info['fileid'] = $file->getId();
            $info['etag'] = $file->getEtag();

            // Inject permissions and convert to string
            $info['permissions'] = \OCA\Memories\Util::permissionsToStr($file->getPermissions());

            // Inject other file parameters that are cheap to get now
            $info['mimetype'] = $file->getMimeType();
            $info['size'] = $file->getSize();
            $info['basename'] = $file->getName();

            // Allow these ony for logged in users
            $user = $this->userSession->getUser();
            if (null !== $user) {
                { // Get the path of the file if in current user's files
                    $path = $file->getPath();
                    $parts = explode('/', $path);
                    if (\count($parts) > 3 && $parts[1] === $user->getUID()) {
                        $info['filename'] = $path;
                    }
                }

                // Get list of tags for this file
                if ($tags) {
                    $info['tags'] = $this->getTags($id);
                }

                // Get latest exif data if requested
                if ($current) {
                    $info['current'] = Exif::getExifFromFile($file);
                }

                // Get clusters for this file
                if ($clusters) {
                    $clist = [];
                    foreach (explode(',', $clusters) as $type) {
                        $backend = \OC::$server->get(\OCA\Memories\ClustersBackend\Manager::class)->get($type);
                        if ($backend->isEnabled()) {
                            $clist[$type] = $backend->getClusters($id);
                        }
                    }
                    $info['clusters'] = $clist;
                }
            }

            return new JSONResponse($info, Http::STATUS_OK);
        });
    }

    /**
     * @NoAdminRequired
     *
     * Set the exif data for a file.
     *
     * @param int fileid
     * @param array  raw exif data
     */
    public function setExif(int $id, array $raw): Http\Response
    {
        return Util::guardEx(function () use ($id, $raw) {
            $file = $this->fs->getUserFile($id);

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
     * @NoAdminRequired
     */
    public function editImage(
        int $id,
        string $name,
        int $width,
        int $height,
        ?float $quality,
        string $extension,
        array $state
    ): Http\Response {
        return Util::guardEx(function () use ($id, $name, $width, $height, $quality, $extension, $state) {
            // Get the file
            $file = $this->fs->getUserFile($id);

            // Check if creating a copy
            $copy = $name !== $file->getName();

            // Check if user has permissions to do this
            if (!$file->isUpdateable() || ($copy && !$file->getParent()->isCreatable())) {
                throw Exceptions::ForbiddenFileUpdate($file->getName());
            }

            // Check if target copy file exists
            if ($copy && $file->getParent()->nodeExists($name)) {
                throw Exceptions::ForbiddenFileUpdate($name);
            }

            // Check if we have imagick
            if (!class_exists('Imagick')) {
                throw Exceptions::Forbidden('Imagick extension is not available');
            }

            // Read the image
            $image = new \Imagick();
            $image->readImageBlob($file->getContent());

            // Due to a bug in filerobot, the provided width and height may be swapped
            // 1. If the user does not rotate the image, we're fine
            // 2. If image is rotated and user doesn't change the save resolution,
            //    the wxh corresponds to the original image, not the rotated one
            // 3. If image is rotated and user changes the save resolution,
            //    the wxh corresponds to the rotated image.
            $iw = $image->getImageWidth();
            $ih = $image->getImageHeight();
            $shouldResize = $width !== $iw || $height !== $ih;

            // Apply the edits
            (new Service\FileRobotMagick($image, $state))->apply();

            // Resize the image
            $iw = $image->getImageWidth();
            $ih = $image->getImageHeight();
            if ($shouldResize && $width && $height && ($iw !== $width || $ih !== $height)) {
                $image->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1, true);
            }

            // Set image format
            $image->setImageFormat($extension);

            // Set quality if specified
            if (null !== $quality && $quality >= 0 && $quality <= 1) {
                $image->setImageCompressionQuality((int) round(100 * $quality));
            }

            // Save the image
            $blob = $image->getImageBlob();

            // Save the file
            if ($copy) {
                $file = $file->getParent()->newFile($name, $blob);
            } else {
                $file->putContent($blob);
            }

            // Make sure the preview is updated
            \OC::$server->get(\OCP\IPreview::class)->getPreview($file);

            return $this->info($file->getId(), true);
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
            $image->setImageCompressionQuality(85);
            $blob = $image->getImageBlob();
            $mimetype = $image->getImageMimeType();
        } catch (\ImagickException $e) {
            throw Exceptions::Forbidden('Imagick failed to convert image: '.$e->getMessage());
        } finally {
            $image->clear();
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
