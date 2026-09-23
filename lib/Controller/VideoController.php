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
use OCA\Memories\Db\FsManager;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Exceptions;
use OCA\Memories\Exif;
use OCA\Memories\HttpResponseException;
use OCA\Memories\Service\BinExt;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

final class VideoController extends ApiController
{
    public function __construct(
        IRequest $request,
        protected LoggerInterface $logger,
        protected TimelineQuery $tq,
        protected FsManager $fs,
        protected IClientService $clientService,
        protected SystemConfig $systemConfig,
        protected BinExt $binExt,
        protected Exif $exif,
    ) {
        parent::__construct(Application::APPNAME, $request);
    }

    /**
     * Transcode a video to HLS by proxy.
     */
    #[NoAdminRequired]
    #[PublicPage]
    #[NoCSRFRequired]
    public function transcode(string $client, int $fileid, string $profile): Http\Response
    {
        return $this->proxyProfile($client, $fileid, $profile);
    }

    /**
     * Serve a storyboard VTT or sprite for timeline hover previews.
     */
    #[NoAdminRequired]
    #[PublicPage]
    #[NoCSRFRequired]
    public function storyboard(string $client, int $fileid, string $profile): Http\Response
    {
        return Util::guardEx(function () use ($client, $fileid, $profile) {
            if (1 !== preg_match('#^(storyboard\.vtt|storyboard-\d+\.jpg)$#', $profile)) {
                throw Exceptions::BadRequest('Invalid storyboard file');
            }

            return $this->proxyProfile($client, $fileid, $profile);
        });
    }

    /**
     * Return the live video part of a Live Photo.
     */
    #[NoAdminRequired]
    #[PublicPage]
    #[NoCSRFRequired]
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
                    $blob = $this->exif->getBinaryExifProp($path, '-trailer');
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
                    $blob = $this->exif->getBinaryExifProp($path, "-{$field}");
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
            if ($transcode && !$this->systemConfig->get('memories.vod.disable')) {
                // If video path not given, write to temp file
                if (!$liveVideoPath) {
                    $liveVideoPath = $this->postFile($transcode, $blob)['path'];
                }

                // If this is H.264 it won't get transcoded anyway
                if ($liveVideoPath) {
                    return Util::guardExDirect(function (Http\IOutput $out) use ($transcode, $liveVideoPath) {
                        // Temp upload with no fileid: transcoded without disk cache.
                        $this->getUpstream($out, $transcode, 0, $liveVideoPath, 'max.mp4');
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

    private function proxyProfile(string $client, int $fileid, string $profile): Http\Response
    {
        return Util::guardEx(function () use ($client, $fileid, $profile) {
            // Make sure transcoding is enabled
            if ($this->systemConfig->get('memories.vod.disable')) {
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

            $etag = $file->getEtag();

            return Util::guardExDirect(function (Http\IOutput $out) use ($client, $fileid, $path, $profile, $etag) {
                try {
                    $status = $this->getUpstream($out, $client, $fileid, $path, $profile, $etag);
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

    private function getUpstream(Http\IOutput $out, string $client, int $fileid, string $path, string $profile, string $etag = ''): int
    {
        $this->binExt->ensureGoVod();

        $url = $this->binExt->getGoVodEndpoint($client, 'vod');

        $data = [
            'client' => $client,
            'fileid' => $fileid,
            'etag' => $etag,
            'path' => $path,
            'profile' => $profile,
            'query' => [
                'albums' => $this->request->getParam('albums'),
                'token' => $this->request->getParam('token'),
                'codecs' => $this->request->getParam('codecs'),
            ],
            'config' => $this->binExt->goVodTConfig(),
        ];

        ignore_user_abort(true);

        try {
            $response = $this->clientService->newClient()->post($url, [
                'json' => $data,
                'headers' => [
                    'X-Go-Vod-Version' => BinExt::GOVOD_VER,
                ],
                'stream' => true,
                'http_errors' => false,
                'timeout' => 0,
                'nextcloud' => ['allow_local_address' => true],
            ]);
        } catch (\Exception) {
            return 0;
        }

        $returnCode = $response->getStatusCode();

        if (200 === $returnCode) {
            if (200 !== $out->getHttpResponseCode()) {
                $out->setHttpResponseCode(200);
            }

            foreach (['Content-Type', 'Content-Length'] as $name) {
                $value = $response->getHeader($name);
                if ('' !== $value) {
                    $out->setHeader("{$name}: {$value}");
                }
            }

            // Caching headers
            if (str_ends_with($profile, 'mp4') || str_ends_with($profile, '.vtt') || str_ends_with($profile, '.jpg')) {
                // cache full video and storyboards 24 hours
                $out->setHeader('Cache-Control: max-age=86400, public');
            } else {
                // no caching of segments
                $out->setHeader('Cache-Control: no-cache, no-store, must-revalidate');
            }

            $stream = $response->getBody();

            // On Safari with MP4, chunked transfer encoding is not supported
            // So we need to read the whole file into memory and send it.
            if (preg_match('/^((?!chrome|android).)*safari/i', $this->request->getHeader('User-Agent'))) {
                $body = \is_resource($stream) ? stream_get_contents($stream) : (string) $stream;
                if (false !== $body) {
                    $out->setHeader('Content-Length: '.\strlen($body)); // critical
                    $out->setOutput($body);
                }
            } elseif (\is_resource($stream)) {
                $out->setReadfile($stream);
            } else {
                $out->setOutput((string) $stream);
            }

            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        return $returnCode;
    }

    /**
     * POST to go-vod to create a temporary file.
     *
     * @return mixed The response from upstream
     */
    private function postFile(string $client, string $blob): mixed
    {
        $this->binExt->ensureGoVod();

        $url = $this->binExt->getGoVodEndpoint($client, 'create');

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
        $liveFiles = array_map(fn ($r) => $this->fs->getUserFileOrNull((int) $r['fileid']), $liveRecords);
        $liveFiles = array_filter($liveFiles, static fn ($f) => $f instanceof File);

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
