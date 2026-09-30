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
use OCA\Memories\Service\ServiceManager;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\Files\File;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\IURLGenerator;
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
        protected ServiceManager $serviceManager,
        protected Util $util,
        protected IURLGenerator $urlGenerator,
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
        return $this->proxyUpstream($client, $fileid, $profile);
    }

    /**
     * Serve a storyboard VTT or sprite for timeline hover previews.
     */
    #[NoAdminRequired]
    #[PublicPage]
    #[NoCSRFRequired]
    public function storyboard(string $client, int $fileid, string $profile): Http\Response
    {
        return $this->util->guardEx(function () use ($client, $fileid, $profile) {
            if (1 !== preg_match('#^(storyboard\.vtt|storyboard-\d+\.jpg)$#', $profile)) {
                throw Exceptions::BadRequest('Invalid storyboard file');
            }

            return $this->proxyUpstream($client, $fileid, $profile);
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
        return $this->util->guardEx(function () use ($fileid, $liveid, $format, $transcode) {
            // Check file liveid
            if (!$liveid) {
                throw Exceptions::MissingParameter('liveid');
            }

            // go-vod might call back on this endpoint, allow service tokens.
            if ($token = $this->request->getHeader(ServiceManager::SERVICE_TOKEN_HEADER)) {
                $file = $this->serviceManager->getServiceTokenFile($token, $fileid);
            } else {
                $file = $this->fs->getUserFile($fileid);
            }

            /** @var ?File $liveFile separate live video file */
            $liveFile = null;

            // Check if the live video is stored in a separate file (Apple MOV)
            if (!str_starts_with($liveid, 'self__')) {
                $liveFile = $this->getClosestLiveVideo($file);
                if (null === $liveFile) {
                    throw Exceptions::NotFound('live video file');
                }
            }

            // Transcode through go-vod: it fetches the full video back.
            if ($transcode && !$this->systemConfig->get('memories.vod.disable')) {
                if ($liveFile) {
                    return $this->proxyUpstream($transcode, $liveFile->getId(), 'max.mp4');
                }

                return $this->proxyUpstream($transcode, $fileid, 'livephoto.mp4', $liveid);
            }

            // Requested IPhoto object for the live video
            if ('json' === $format) {
                if (!$liveFile) {
                    throw Exceptions::BadRequest('Invalid format');
                }

                return new JSONResponse([
                    'fileid' => $liveFile->getId(),
                    'etag' => $liveFile->getEtag(),
                    'basename' => $liveFile->getName(),
                    'mimetype' => $liveFile->getMimeType(),
                ]);
            }

            if ($liveFile) {
                return new RedirectResponse($this->downloadUrl($liveFile->getId()));
            }

            // Video is inside the file
            $path = $file->getStorage()->getLocalFile($file->getInternalPath())
                ?: throw Exceptions::BadRequest('[Video] File path missing (self__*)');

            // Different manufacturers have different formats
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
                throw Exceptions::BadRequest('Invalid liveid');
            }

            // Data not found
            if (!$blob) {
                throw Exceptions::NotFound('live video data');
            }

            // Make and send response
            $response = new DataDisplayResponse($blob, Http::STATUS_OK, []);
            $response->setHeaders([
                'Content-Type' => 'video/mp4',
                'Content-Disposition' => "attachment; filename=\"{$file->getName()}.mp4\"",
            ]);
            $response->cacheFor(3600 * 24, false, false);

            return $response;
        });
    }

    private function proxyUpstream(string $client, int $fileid, string $profile, string $liveid = ''): Http\Response
    {
        return $this->util->guardEx(function () use ($client, $fileid, $profile, $liveid) {
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
            $etag = $file->getEtag();

            return $this->util->guardExDirect(function (Http\IOutput $out) use ($client, $fileid, $profile, $etag, $liveid) {
                try {
                    $status = $this->getUpstream(
                        out: $out,
                        client: $client,
                        fileid: $fileid,
                        profile: $profile,
                        etag: $etag,
                        liveid: $liveid,
                    );
                    if (303 === $status) {
                        return; // redirect already sent
                    }
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

    private function getUpstream(
        Http\IOutput $out,
        string $client,
        int $fileid,
        string $profile,
        string $etag,
        string $liveid,
    ): int {
        $this->binExt->ensureGoVod();

        $url = $this->binExt->getGoVodEndpoint($client, 'vod');

        $data = [
            'client' => $client,
            'fileid' => $fileid,
            'etag' => $etag,
            'serviceToken' => $this->serviceManager->mintServiceToken($fileid),
            'profile' => $profile,
            'query' => [
                'albums' => $this->request->getParam('albums'),
                'token' => $this->request->getParam('token'),
                'codecs' => $this->request->getParam('codecs'),
                'liveid' => $liveid,
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

        if (204 === $returnCode && $response->getHeader('X-Go-Vod-Original')) {
            $out->setHttpResponseCode(303); // see Util::guardExDirect
            $out->setHeader('HTTP/1.1 303 See Other');
            $out->setHeader('Location: '.$this->downloadUrl($fileid));

            return 303;
        }

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

    /**
     * Download URL for the file, preserving share context.
     */
    private function downloadUrl(int $fileid): string
    {
        return $this->urlGenerator->linkToRoute('memories.Download.one', [
            'fileid' => $fileid,
            'albums' => $this->request->getParam('albums'),
            'token' => $this->request->getParam('token'),
        ]);
    }
}
