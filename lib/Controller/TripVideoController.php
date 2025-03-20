<?php

declare(strict_types=1);

namespace OCA\Memories\Controller;

use OCP\IDBConnection;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use OCP\IRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;

use Psr\Log\LoggerInterface;
use OCA\Memories\Db\TimelineRoot;
use OCA\Memories\AppInfo\Application;
use OCA\Memories\Exceptions;
use OCA\Memories\Util;
use OCA\Memories\Service\TripMediaService;

class TripVideoController extends Controller
{
    public function __construct(
        IRequest $request,
        private readonly IDBConnection $db,
        private readonly IURLGenerator $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly IRootFolder $rootFolder,
        private readonly TimelineRoot $timelineRoot,
        private readonly string $userId,
        private readonly TripMediaService $tripMediaService,
    ) {
        parent::__construct(Application::APPNAME, $request);
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     */
    public function list(): JSONResponse
    {
        return Util::guardEx(function () {
            $videos = $this->getTripVideos();
            return new JSONResponse($videos);
        });
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     */
    public function get(int $id): Http\Response
    {
        return Util::guardEx(function () use ($id) {
            $trip = $this->getTripById($id);
            if (!$trip) {
                throw new Exceptions\NotFoundException("Trip not found: {$id}");
            }

            $tripName = $trip['custom_name'] ?? $trip['name'] ?? "Trip {$trip['id']}";

            // Check for video file
            $userFolder = $this->rootFolder->getUserFolder($this->userId);
            $videoFolder = "/Memories/TripVideos";

            if (!$userFolder->nodeExists($videoFolder)) {
                throw new Exceptions\NotFoundException("Video folder not found");
            }

            // Look for any video file matching the trip ID
            $folderNode = $userFolder->get($videoFolder);
            if ($folderNode->getType() !== \OCP\Files\FileInfo::TYPE_FOLDER) {
                throw new Exceptions\NotFoundException("Video folder is not a directory");
            }

            $videoFile = null;
            $pattern = "/^trip_video_{$id}_/";

            foreach ($folderNode->getDirectoryListing() as $node) {
                if ($node->getType() !== \OCP\Files\FileInfo::TYPE_FILE) {
                    continue;
                }

                $filename = $node->getName();
                if (preg_match($pattern, $filename)) {
                    $videoFile = $node;
                    break;
                }
            }

            if (!$videoFile) {
                throw new Exceptions\NotFoundException("Video not found for trip: {$id}");
            }

            $response = new StreamResponse($videoFile->fopen('rb'));
            $response->addHeader('Content-Type', 'video/mp4');
            $response->addHeader('Content-Disposition', 'inline; filename="' . $videoFile->getName() . '"');
            $response->addHeader('Accept-Ranges', 'bytes');

            return $response;
        });
    }

    private function getTripVideos(): array
    {
        $videos = [];
        $userFolder = $this->rootFolder->getUserFolder($this->userId);

        $videoFolderPath = '/Memories/TripVideos';
        if (!$userFolder->nodeExists($videoFolderPath)) {
            return [];
        }

        $videoFolder = $userFolder->get($videoFolderPath);
        if ($videoFolder->getType() !== \OCP\Files\FileInfo::TYPE_FOLDER) {
            return [];
        }

        $nodes = $videoFolder->getDirectoryListing();
        foreach ($nodes as $node) {
            if ($node->getType() !== \OCP\Files\FileInfo::TYPE_FILE) {
                continue;
            }

            $filename = $node->getName();
            if (substr($filename, -4) !== '.mp4') {
                continue;
            }

            if (preg_match('/^trip_video_(\d+)_/', $filename, $matches)) {
                $tripId = (int)$matches[1];
                $trip = $this->getTripById($tripId);

                if ($trip) {
                    $videos[] = [
                        'id' => $tripId,
                        'name' => $trip['custom_name'] ?? $trip['name'] ?? "Trip {$tripId}",
                        'location' => $trip['location'] ?? '',
                        'timeframe' => $trip['timeframe'] ?? '',
                        'file' => $filename,
                        'size' => $node->getSize(),
                        'mtime' => $node->getMTime(),
                        'url' => $this->urlGenerator->linkToRoute(
                            'memories.TripVideo.get',
                            ['id' => $tripId]
                        ),
                    ];
                }
            }
        }

        usort($videos, function ($a, $b) {
            return $b['mtime'] - $a['mtime'];
        });

        return $videos;
    }

    private function getTripById(int $tripId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('memories_trips')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($tripId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))
           ->setMaxResults(1);

        $result = $qb->executeQuery();
        $trip = $result->fetch();
        $result->closeCursor();

        return $trip ?: null;
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[^\w\s.-]/', '_', $filename);
        $filename = preg_replace('/\s+/', ' ', $filename);
        return substr(trim($filename), 0, 100);
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     * @param int $id Trip ID
     * @param int|null $max_video_duration Optional max duration for video clips in seconds
     * @return JSONResponse
     */
    public function getSlideshowMedia(int $id, ?int $max_video_duration = 10): JSONResponse
    {
        try {
            // Log basic info
            $this->logger->debug("Slideshow request for tripId={$id}");

            // Check authentication
            if (!$this->userId) {
                return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
            }

            // Get trip info
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'name')
               ->from('memories_trips')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
               ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))
               ->setMaxResults(1);

            $cursor = $qb->executeQuery();
            $trip = $cursor->fetch();
            $cursor->closeCursor();

            if (!$trip) {
                return new JSONResponse(['error' => 'Trip not found'], Http::STATUS_NOT_FOUND);
            }

            // Get media files using the shared service
            $maxItems = 12; // Using the same default for consistency
            $mediaFiles = $this->tripMediaService->getTripMedia($id, $this->userId, $maxItems, $max_video_duration);

            if (empty($mediaFiles)) {
                $this->logger->debug('No media found for trip: ' . $id);
                return new JSONResponse([]);
            }

            // Transform selected media to slideshow format
            $slideshowMedia = [];
            foreach ($mediaFiles as $file) {
                $isVideo = isset($file['mimetype_str']) && (
                    strpos($file['mimetype_str'], 'video/') === 0 ||
                    in_array($file['mimetype_str'], \OCA\Memories\AppInfo\Application::VIDEO_MIMES)
                );

                $item = [
                    'id' => (int)$file['fileid'],
                    'type' => $isVideo ? 'video' : 'image',
                    'datetaken' => $file['datetaken'],
                    'displayDuration' => $isVideo ? null : 3, // Default display time for images
                ];

                // Add optional fields
                if (isset($file['etag'])) $item['etag'] = $file['etag'];
                if (isset($file['path'])) $item['path'] = $file['path'];
                if (isset($file['w'])) $item['w'] = (int)$file['w'];
                if (isset($file['h'])) $item['h'] = (int)$file['h'];
                if (isset($file['video_duration'])) $item['duration'] = (float)$file['video_duration'];
                if (isset($file['mimetype_str'])) $item['mime'] = $file['mimetype_str'];

                // Add URLs for frontend
                $item['previewUrl'] = $this->urlGenerator->linkToRoute(
                    'memories.Image.preview',
                    ['id' => $file['fileid']]
                );

                $item['downloadUrl'] = $this->urlGenerator->linkToRoute(
                    'memories.Download.one',
                    ['fileid' => $file['fileid']]
                );

                $slideshowMedia[] = $item;
            }

            // Log the number of selected items
            $this->logger->debug("Returning " . count($slideshowMedia) . " media items for tripId={$id}");

            return new JSONResponse($slideshowMedia);

        } catch (\Throwable $e) {
            $this->logger->error('Error in getSlideshowMedia: ' . $e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

}
