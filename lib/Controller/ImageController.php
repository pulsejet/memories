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

use OC\Preview\PreviewService;
use OCA\Memories\AppInfo\Application;
use OCA\Memories\Db\FsManager;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Exceptions;
use OCA\Memories\Exif;
use OCA\Memories\Preview\Vips;
use OCA\Memories\Service\Index;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IPreview;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;

const IMAGICK_SAFE = '/^image\/(x-)?(png|jpeg|gif|bmp|tiff|webp|hei(f|c)|avif|dcraw)$/';

final class ImageController extends ApiController
{
    public function __construct(
        IRequest $request,
        protected FsManager $fs,
        protected TimelineQuery $tq,
        protected IUserSession $userSession,
        protected IPreview $preview,
        protected PreviewService $previewService,
        protected ISystemTagObjectMapper $tagObjectMapper,
        protected ISystemTagManager $tagManager,
        protected Exif $exif,
        protected SystemConfig $systemConfig,
        protected Util $util,
        protected Index $index,
        protected Vips $vips,
    ) {
        parent::__construct(Application::APPNAME, $request);
    }

    /**
     * Get preview of image.
     *
     * @psalm-param 'cover'|'fill' $mode
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function preview(
        int $id,
        int $x = 32,
        int $y = 32,
        bool $a = false,
        string $mode = 'fill',
    ): Http\Response {
        return $this->util->guardEx(function () use ($id, $x, $y, $a, $mode) {
            if (-1 === $id || 0 === $x || 0 === $y) {
                throw Exceptions::MissingParameter('id, x, y');
            }

            // Get preview for this file
            $file = $this->fs->getUserFile($id);

            try {
                $preview = $this->preview
                    ->getPreview($file, $x, $y, !$a, $mode)
                ;
            } catch (\OCP\Files\NotFoundException $e) {
                // This exception is thrown if no generator is found,
                // throw an HTTP exception to prevent spamming admin log.
                throw Exceptions::NotFound($e->getMessage());
            }

            // Stream the preview file directly without buffering into PHP
            $response = new Http\FileDisplayResponse($preview, Http::STATUS_OK, [
                'Content-Type' => $preview->getMimeType(),
            ]);

            // Get the filename. We need to move the extension from
            // the preview file to the filename's end if it's not there
            // Do the comparison case-insensitive
            $filename = $file->getName();
            if ($ext = pathinfo($preview->getName(), PATHINFO_EXTENSION)) {
                if (!str_ends_with(strtolower($filename), strtolower('.'.$ext))) {
                    $filename .= '.'.$ext;
                }
            }
            $response->addHeader('Content-Disposition', Util::getContentDisposition($filename));
            $response->cacheFor(3600 * 24, false, true);

            return $response;
        });
    }

    /**
     * Get preview of many images.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function multipreview(array $files): Http\Response
    {
        return $this->util->guardExDirect(function (Http\IOutput $out) use ($files): void {
            // Filter files with valid parameters
            $files = array_filter($files, static function (array $file) {
                return isset($file['reqid'], $file['fileid'], $file['x'], $file['y'], $file['a'])
                    && ((int) $file['fileid'] > 0)
                    && ((int) $file['x'] > 0 || -1 === (int) $file['y'])
                    && ((int) $file['y'] > 0 || -1 === (int) $file['x']);
            });

            // Sort files by size, ascending
            usort($files, static function (array $a, array $b) {
                $ax = (int) $a['x'];
                $ay = (int) $a['y'];
                $bx = (int) $b['x'];
                $by = (int) $b['y'];

                $aArea = $ax > 0 && $ay > 0 ? $ax * $ay : PHP_INT_MAX;
                $bArea = $bx > 0 && $by > 0 ? $bx * $by : PHP_INT_MAX;

                return $aArea <=> $bArea;
            });

            $requestedFileIds = array_map(static fn ($bodyFile) => (int) $bodyFile['fileid'], $files);
            $availablePreviews = $this->previewService->getAvailablePreviews($requestedFileIds);

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
                    $hasMax = false;
                    foreach ($availablePreviews[$fileid] ?? [] as $preview) {
                        if ($preview->isMax()) {
                            $hasMax = true;

                            break;
                        }
                    }
                    if (!$hasMax) {
                        continue;
                    }

                    // Add this preview to the response
                    $file = $this->fs->getUserFile($fileid);
                    $preview = $this->preview->getPreview($file, $x, $y, !$a, \OCP\IPreview::MODE_FILL);
                    $size = (int) $preview->getSize();
                    if ($size <= 0) {
                        continue;
                    }

                    $stream = $preview->read();
                    if (false === $stream) {
                        continue;
                    }

                    // Encode parameters
                    $json = json_encode([
                        'reqid' => $reqid,
                        'len' => $size,
                        'type' => $preview->getMimeType(),
                    ]);

                    // Send the length of the json as a single byte
                    if (false === $json || \strlen($json) > 255) {
                        fclose($stream);

                        continue;
                    }
                    $out->setOutput(\chr(\strlen($json)));
                    $out->setOutput($json);

                    // Stream the image
                    $out->setReadfile($stream);
                    fclose($stream);
                } catch (\OCP\Files\NotFoundException $e) {
                    continue;
                } catch (\Exception $e) {
                    continue;
                }
            }
        });
    }

    /**
     * Get EXIF info for an image with file id.
     */
    #[NoAdminRequired]
    #[PublicPage]
    public function info(
        int $id,
        bool $basic = false,
        bool $current = false,
        bool $tags = false,
        string $clusters = '',
    ): Http\Response {
        return $this->util->guardEx(function () use ($id, $basic, $current, $tags, $clusters) {
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
            $info['mtime'] = $file->getMTime();

            // Get file owner name for shared files
            if ($owner = $file->getOwner()) {
                $info['owneruid'] = $owner->getUID();
                $info['ownername'] = $owner->getDisplayName();
            }

            // Get the path of the file relative to root
            // For public shares, get path relative to share root
            // For logged in users, get path relative to user folder
            if ($user = $this->userSession->getUser()) {
                // Get the path of the file relative to current user
                // "/admin/files/Photos/Camera/20230821_135017.jpg" => "/Photos/..."
                $parts = explode('/', $file->getPath());
                if (\count($parts) > 3 && 'files' === $parts[2] && $parts[1] === $user->getUID()) {
                    $filename = '/'.implode('/', \array_slice($parts, 3));
                    $info['filename'] = $filename;

                    try {
                        $timelinePaths = $this->systemConfig->getTimelinePaths($user->getUID());
                        $info['intimeline'] = Util::matchesAnyPrefix($filename, $timelinePaths);
                    } catch (\Throwable) {
                        // ignore errors, assume not in timeline
                    }
                }

                // Get list of tags for this file
                if ($tags) {
                    $info['tags'] = $this->getTags($id);
                }

                // Get latest exif data if requested
                if ($current) {
                    $info['current'] = $this->exif->getExifFromFile($file);
                }

                // Get clusters for this file
                if ($clusters) {
                    $clist = [];
                    foreach (explode(',', $clusters) as $type) {
                        $backend = \OCA\Memories\ClustersBackend\Manager::get($type);
                        if ($backend->isEnabled()) {
                            $clist[$type] = $backend->getClusters($id);
                        }
                    }
                    $info['clusters'] = $clist;
                }
            } elseif ($shareNode = $this->fs->getShareNode()) {
                // For public shares, get path relative to share root
                $sharePath = $shareNode->getPath();
                $filePath = $file->getPath();
                if (str_starts_with($filePath, $sharePath)) {
                    $info['filename'] = substr($filePath, \strlen($sharePath));
                }
            }

            return new JSONResponse($info, Http::STATUS_OK);
        });
    }

    /**
     * Set the exif data for a file (supports public shares).
     */
    #[NoAdminRequired]
    #[PublicPage]
    public function setExif(int $id, array $raw): Http\Response
    {
        return $this->util->guardEx(function () use ($id, $raw) {
            $file = $this->fs->getUserFile($id);

            // Check if user has permissions
            if (!$file->isUpdateable() || $this->systemConfig->isEncryptionEnabled()) {
                throw Exceptions::ForbiddenFileUpdate($file->getName());
            }

            // Check if allowed to edit file
            $mime = $file->getMimeType();
            if (!\in_array($mime, $this->exif->allowedEditMimetypes(), true)) {
                $name = $file->getName();

                throw Exceptions::Forbidden("Cannot edit file {$name} (blacklisted type {$mime})");
            }

            // Only tags editable from the UI may be set (see EditMetadataModal)
            $allowed = [
                'AllDates', 'Orientation', 'Title', 'Description', 'Label',
                'Make', 'Model', 'LensModel', 'Copyright', 'GPSLatitude',
                'GPSLongitude', 'GPSLatitudeRef', 'GPSLongitudeRef', 'GPSCoordinates',
            ];
            foreach (array_keys($raw) as $key) {
                if (!\in_array($key, $allowed, true)) {
                    throw Exceptions::BadRequest('Invalid EXIF tag');
                }
            }

            // Set the exif data
            $this->exif->setFileExif($file, $raw);

            // If rotation changed then update the previews
            if ($raw['Orientation'] ?? false) {
                $this->refreshPreviews($file);
            }

            return $this->info($id, true);
        });
    }

    /**
     * Force reindex a single file (supports logged-in users only).
     */
    #[NoAdminRequired]
    public function reindex(int $id): Http\Response
    {
        return $this->util->guardEx(function () use ($id) {
            $file = $this->fs->getUserFile($id);
            if (!$file->isUpdateable()) {
                throw Exceptions::ForbiddenFileUpdate($file->getName());
            }

            $this->index->indexFile($file, failSkip: true, force: true);
            $this->refreshPreviews($file);

            return new JSONResponse(
                ['indexed' => true],
                Http::STATUS_OK,
            );
        });
    }

    /**
     * Get a full resolution decodable image for editing from a file.
     * The returned image may be png / webp / avif / jpeg / gif.
     * These formats are supported by all browsers.
     *
     * @param string $id   the FileID of the image
     * @param bool   $hevc whether the client supports HEVC
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function decodable(string $id, bool $hevc = false): Http\Response
    {
        return $this->util->guardEx(function () use ($id, $hevc) {
            $file = $this->fs->getUserFile((int) $id);

            // Check if valid image
            $mimetype = $file->getMimeType();
            if (!\in_array($mimetype, Application::IMAGE_MIMES, true)) {
                throw Exceptions::Forbidden('Not an image');
            }

            // Skip conversion and streaming if the client already has this version
            $etag = $file->getEtag();
            if (trim(trim($this->request->getHeader('If-None-Match')), '"') === $etag) {
                $response = new Http\Response(Http::STATUS_NOT_MODIFIED);
                $response->setETag($etag);

                return $response;
            }

            // Stream directly if browser-decodable to avoid buffering into PHP
            $directMimes = [
                'image/png',
                'image/webp',
                'image/avif',
                'image/jpeg',
                'image/gif',
            ];

            // Client can indicate support for HEVC images.
            if ($hevc) {
                array_push(
                    $directMimes,
                    'image/heic',
                    'image/heif',
                    'image/x-heic',
                    'image/x-heif',
                );
            }

            // Stream directly if mimetype matches.
            if (\in_array($mimetype, $directMimes, true)) {
                $response = new Http\FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $mimetype]);
                $response->cacheFor(3600 * 24, false, false);

                return $response;
            }

            // Convert image to JPEG (reads from local path to avoid buffering into PHP)
            $path = $file->getStorage()->getLocalFile($file->getInternalPath());
            [$blob, $mimetype, $provider] = $this->getImageJPEG($path, $mimetype);

            // Return the image
            $response = new Http\DataDownloadResponse($blob, $file->getName().'.jpg', $mimetype);
            $response->setETag($etag);
            $response->setLastModified((new \DateTime())->setTimestamp($file->getMTime()));
            $response->cacheFor(3600 * 24, false, false);

            if ($this->systemConfig->get('debug')) {
                $response->addHeader('X-Preview-Provider', $provider);
            }

            return $response;
        });
    }

    #[NoAdminRequired]
    #[PublicPage]
    public function editImage(
        int $id,
        string $name,
        string $extension,
        array $edits = [],
    ): Http\Response {
        return $this->util->guardEx(function () use ($id, $name, $extension, $edits) {
            // Get the file
            $file = $this->fs->getUserFile($id);

            // Live photos must always be saved as a copy.
            $isLivePhoto = (bool) $this->tq->getLiveId($file->getId());
            if ($isLivePhoto && $name === $file->getName()) {
                $name = $file->getParent()->getNonExistingName($name);
            }

            // Check if creating a copy
            $copy = $name !== $file->getName();

            // Single-file shares must not allow copy-edit.
            if ($copy && $this->fs->getShareNode() instanceof \OCP\Files\File) {
                throw Exceptions::ForbiddenFileUpdate($name);
            }

            // Check if user has permissions to do this
            if (!$file->isUpdateable() || ($copy && !$file->getParent()->isCreatable())) {
                throw Exceptions::ForbiddenFileUpdate($file->getName());
            }

            // Name must be a single path segment with no control characters.
            if ('.' === $name || '..' === $name || !preg_match('/^[^\/\0[:cntrl:]]+\z/u', $name)) {
                throw Exceptions::BadRequest('Invalid file name');
            }
            $extLower = strtolower($extension);
            if (!\in_array($extLower, ['jpeg', 'jpg', 'png', 'webp', 'avif'], true)) {
                throw Exceptions::BadRequest('Invalid image format');
            }

            // Check if target copy file exists
            if ($copy && $file->getParent()->nodeExists($name)) {
                throw Exceptions::ForbiddenFileUpdate($name);
            }

            // Read the image from the local path to avoid buffering into PHP
            $path = $file->getStorage()->getLocalFile($file->getInternalPath());

            // Apply the vips-editor edits via the static vips binary.
            $blob = $this->editImageVips($path, $extLower, $edits);

            // Strip live photo identifiers.
            if ($isLivePhoto) {
                $blob = $this->exif->stripLivePhotoTags($blob);
            }

            // Save the file
            if ($copy) {
                $file = $file->getParent()->newFile($name, $blob);
            } else {
                $file->putContent($blob);
            }

            // Make sure the preview is updated
            $this->preview->getPreview($file);

            return $this->info($file->getId(), true);
        });
    }

    /**
     * Delete a file (supports public shares).
     */
    #[NoAdminRequired]
    #[PublicPage]
    public function deleteFile(int $id): Http\Response
    {
        return $this->util->guardEx(function () use ($id) {
            // Get the file
            $file = $this->fs->getUserFile($id);

            // Check if user has delete permissions
            if (!$file->isDeletable()) {
                throw Exceptions::Forbidden('No delete permission for this file');
            }

            // Delete the file
            $file->delete();

            return new JSONResponse(['deleted' => true], Http::STATUS_OK);
        });
    }

    /**
     * Apply vips-editor edits via the static vips binary.
     *
     * @param array<int, array<string, mixed>> $edits
     */
    private function editImageVips(string $path, string $extension, array $edits): string
    {
        // Basic shape check so vips errors stay actionable.
        foreach ($edits as $edit) {
            if (!\is_array($edit) || !isset($edit['edit']) || !\is_string($edit['edit'])) {
                throw Exceptions::BadRequest('Invalid edit entry');
            }
        }

        // Saver is picked from the output extension.
        $outputMap = [
            'jpeg' => 'output.jpg',
            'jpg' => 'output.jpg',
            'png' => 'output.png',
            'webp' => 'output.webp',
            'avif' => 'output.avif',
        ];
        $output = $outputMap[$extension] ?? null;
        if (null === $output) {
            throw Exceptions::BadRequest('Invalid image format');
        }

        // Vips convert quality (JPEG/WebP/AVIF). PNG ignores it.
        $qualityInt = 90;

        try {
            $blob = $this->vips->convertFormat($path, $output, $qualityInt, array_values($edits));
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            // Edit validation errors come from the vips edits parser.
            if (str_contains($msg, 'edits')) {
                throw Exceptions::BadRequest($msg);
            }

            throw Exceptions::Forbidden('VIPS failed: '.$msg);
        }

        return $blob;
    }

    /**
     * Given an image file, return a JPEG blob.
     *
     * @param string $path     Local filesystem path of the image
     * @param string $mimetype Mimetype of image data
     *
     * @return string[] [blob, mimetype, provider]
     *
     * @psalm-return list{string, string, string}
     */
    private function getImageJPEG(string $path, $mimetype): array
    {
        // Prefer vips convert when available (faster, no imagick needed)
        if ($this->vips->isEnabled()) {
            try {
                return [$this->vips->convertFormat($path, 'output.jpg', 85), 'image/jpeg', 'vips'];
            } catch (\Throwable) {
                // fall through to imagick
            }
        }

        // Read from the local path to avoid buffering the file into PHP
        $image = self::getImagick($path);

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

        return [$blob, $mimetype, 'imagick'];
    }

    /**
     * Get the tags for a file.
     *
     * @return string[]
     */
    private function getTags(int $fileId): array
    {
        // Make sure tags are enabled
        if (!$this->systemConfig->tagsIsEnabled()) {
            return [];
        }

        // Get the tag ids for this file
        $tagIds = $this->tagObjectMapper->getTagIdsForObjects([(string) $fileId], 'files')[(string) $fileId];

        // Get all matching tag objects
        $tags = $this->tagManager->getTagsByIds($tagIds);

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
            // Delete all available previews
            $fileId = $file->getId();
            foreach ($this->previewService->getAvailablePreviewsForFile($fileId) as $preview) {
                $this->previewService->deletePreview($preview);
            }

            // Get the preview to regenerate
            $this->preview->getPreview($file, 32, 32, true, \OCP\IPreview::MODE_FILL);
        } catch (\Exception $e) {
            return;
        }
    }

    /**
     * Get an instance of Imagick for the file at the given local path.
     *
     * @param string $path Local filesystem path of the image
     *
     * @return \Imagick
     */
    private static function getImagick(string $path)
    {
        // Check if Imagick is available
        if (!class_exists('Imagick')) {
            throw Exceptions::Forbidden('Imagick extension is not available');
        }

        try {
            $image = new \Imagick();

            // Check if image is safe
            $image->pingImage($path);
            if (!preg_match(IMAGICK_SAFE, $mime = $image->getImageMimeType())) {
                throw Exceptions::Forbidden("Image type {$mime} not allowed");
            }

            // Read the image file
            $image->readImage($path);

            return $image;
        } catch (\ImagickException $e) {
            throw Exceptions::Forbidden('Imagick failed to read image: '.$e->getMessage());
        }
    }
}
