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
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;

const IMAGICK_SAFE = '/^image\/(x-)?(png|jpeg|gif|bmp|tiff|webp|hei(f|c)|avif|dcraw)$/';

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
        string $mode = 'fill',
    ): Http\Response {
        return Util::guardEx(function () use ($id, $x, $y, $a, $mode) {
            if (-1 === $id || 0 === $x || 0 === $y) {
                throw Exceptions::MissingParameter('id, x, y');
            }

            // Get preview for this file
            $file = $this->fs->getUserFile($id);
            $preview = \OC::$server->get(\OCP\IPreview::class)->getPreview($file, $x, $y, !$a, $mode);

            // Get the filename. We need to move the extension from
            // the preview file to the filename's end if it's not there
            // Do the comparison case-insensitive
            $filename = $file->getName();
            if ($ext = pathinfo($preview->getName(), PATHINFO_EXTENSION)) {
                if (!str_ends_with(strtolower($filename), strtolower('.'.$ext))) {
                    $filename .= '.'.$ext;
                }
            }

            // Generate response with proper content-disposition
            $response = new Http\DataDownloadResponse($preview->getContent(), $filename, $preview->getMimeType());
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
    public function multipreview(array $files): Http\Response
    {
        return Util::guardExDirect(function (Http\IOutput $out) use ($files) {
            // Filter files with valid parameters
            $files = array_filter($files, static function (array $file) {
                return isset($file['reqid'], $file['fileid'], $file['x'], $file['y'], $file['a'])
                    && (int) $file['fileid'] > 0
                    && (int) $file['x'] > 0
                    && (int) $file['y'] > 0;
            });

            // Sort files by size, ascending
            usort($files, static function (array $a, array $b) {
                $aArea = (int) $a['x'] * (int) $a['y'];
                $bArea = (int) $b['x'] * (int) $b['y'];

                return $aArea <=> $bArea;
            });

            /** @var \OCP\IPreview */
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
        string $clusters = '',
    ): Http\Response {
        return Util::guardEx(function () use ($id, $basic, $current, $tags, $clusters) {
            $file = $this->fs->getUserFile($id);

            // Get the image info
            $info = $this->tq->getInfoById($id, $basic);

            // Add fileid and etag
            $info['fileid'] = $file->getId();
            $info['etag'] = $file->getEtag();

            // Inject permissions and convert to string
            $info['permissions'] = Util::permissionsToStr($file->getPermissions());

            // Check if download has been disabled
            if (!$this->fs->canDownload($file)) {
                $info['permissions'] .= 'L';
            }

            // Inject other file parameters that are cheap to get now
            $info['mimetype'] = $file->getMimeType();
            $info['size'] = $file->getSize();
            $info['basename'] = $file->getName();
            $info['uploadtime'] = $file->getUploadTime() ?: $file->getMTime();

            // Allow these ony for logged in users
            if ($user = $this->userSession->getUser()) {
                // Get the path of the file relative to current user
                // "/admin/files/Photos/Camera/20230821_135017.jpg" => "/Photos/..."
                $parts = explode('/', $file->getPath());
                if (\count($parts) > 3 && 'files' === $parts[2] && $parts[1] === $user->getUID()) {
                    $info['filename'] = '/'.implode('/', \array_slice($parts, 3));
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

            // If rotation changed then update the previews
            if ($raw['Orientation'] ?? false) {
                $this->refreshPreviews($file);
            }

            return $this->info($id, true);
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

            /** @var string Name of file */
            $name = $file->getName();

            // Convert image to JPEG if required
            if (!preg_match('/^image\/(png|webp|jpeg|gif)$/', $mimetype)) {
                [$blob, $mimetype] = $this->getImageJPEG($blob, $mimetype);
                $name .= '.jpg';
            }

            // Return the image
            $response = new Http\DataDownloadResponse($blob, $name, $mimetype);
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
        array $state,
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

            // Read the image
            $image = self::getImagick($file->getContent());

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
     * @return string[] [blob, mimetype]
     *
     * @psalm-return list{string, string}
     */
    private function getImageJPEG($blob, $mimetype): array
    {
        // TODO: Use imaginary if available (once HEIC isn't broken)

        // Get an instance of Imagick
        $image = self::getImagick($blob);

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
     *
     * @return string[]
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

        // Get all matching tag objects
        $tags = \OC::$server->get(\OCP\SystemTag\ISystemTagManager::class)->getTagsByIds($tagIds);

        // Filter out the tags that are not user visible
        $visible = array_filter($tags, static fn ($t) => $t->isUserVisible());

        // Get the tag names
        return array_map(static fn ($t) => $t->getName(), $visible);
    }

    /**
     * Invalidate previews for a file.
     *
     * @param \OCP\Files\File $file File to invalidate previews for
     */
    private function refreshPreviews(\OCP\Files\File $file): void
    {
        try {
            $previewRoot = new \OC\Preview\Storage\Root(
                \OC::$server->get(IRootFolder::class),
                \OC::$server->get(\OC\SystemConfig::class),
            );

            // Delete the preview folder
            $fileId = (string) $file->getId();
            $previewRoot->getFolder($fileId)->delete();

            // Get the preview to regenerate
            $previewManager = \OC::$server->get(\OCP\IPreview::class);
            $previewManager->getPreview($file, 32, 32, true, \OCP\IPreview::MODE_FILL);
        } catch (\Exception $e) {
            return;
        }
    }

    /**
     * Get an instance of Imagick for the given blob.
     *
     * @param string $blob Blob of image data
     *
     * @return \Imagick
     */
    private static function getImagick(string $blob)
    {
        // Check if Imagick is available
        if (!class_exists('Imagick')) {
            throw Exceptions::Forbidden('Imagick extension is not available');
        }

        try {
            $image = new \Imagick();

            // Check if image is safe
            $image->pingImageBlob($blob);
            if (!preg_match(IMAGICK_SAFE, $mime = $image->getImageMimeType())) {
                throw Exceptions::Forbidden("Image type {$mime} not allowed");
            }

            // Read the image blob
            $image->readImageBlob($blob);

            return $image;
        } catch (\ImagickException $e) {
            throw Exceptions::Forbidden('Imagick failed to read image: '.$e->getMessage());
        }
    }
}
