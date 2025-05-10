<?php

declare(strict_types=1);

namespace OCA\Memories\ClustersBackend;

use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;

class TripsBackend extends Backend
{
    protected IDBConnection $db;
    protected TimelineQuery $tq;
    protected IRequest $request;
    protected IL10N $l;

    public function __construct(
        IDBConnection $db,
        TimelineQuery $tq,
        IRequest $request,
        IL10N $l
    ) {
        $this->db = $db;
        $this->tq = $tq;
        $this->request = $request;
        $this->l = $l;
    }

    public static function appName(): string
    {
        return 'Trips';
    }

    public static function clusterType(): string
    {
        return 'trips';
    }

    public function isEnabled(): bool
    {
        return SystemConfig::gisType() > 0;
    }

    public function transformDayQuery(IQueryBuilder &$query, bool $aggregate): void
    {
        try {
            $tripId = $this->request->getParam(self::clusterType());
            
            if (!$tripId) {
                return;
            }

            $query->innerJoin('m', 'memories_trip_photos', 'tp', $query->expr()->eq('m.fileid', 'tp.fileid'));
            $query->andWhere($query->expr()->eq('tp.trip_id', $query->createNamedParameter((int) $tripId)));
        } catch (\Exception $e) {
        }
    }

    public static function getClusterId(array $cluster): int|string
    {
        return $cluster['id'];
    }

    public function getClustersInternal(int $fileid = 0): array
    {
        if (!Util::isLoggedIn()) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('t.id', 't.name', 't.start_date', 't.end_date', 't.custom_name', 't.timeframe', 't.location')
           ->from('memories_trips', 't')
           ->where($qb->expr()->eq('t.user_id', $qb->createNamedParameter(Util::getUID())))
           ->orderBy('t.start_date', 'DESC');

        $cursor = $qb->executeQuery();
        $trips = $cursor->fetchAll();
        $cursor->closeCursor();

        $result = [];
        foreach ($trips as $trip) {
            $result[] = [
                'id' => $trip['id'],
                'name' => $trip['custom_name'] ?? $trip['name'],
                'dayid' => (string)$trip['id'],
                'count' => $this->getTripPhotoCount($trip['id']),
                'startDate' => (int)$trip['start_date'],
                'endDate' => (int)$trip['end_date'],
                'timeframe' => $trip['timeframe'] ?? '',
                'location' => $trip['location'] ?? '',
            ];
        }

        return $result;
    }

    protected function getTripPhotoCount(int $tripId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(DISTINCT tp.fileid) as count'))
           ->from('memories_trip_photos', 'tp')
           ->innerJoin('tp', 'memories', 'm', $qb->expr()->eq('tp.fileid', 'm.fileid'))
           ->where($qb->expr()->eq('tp.trip_id', $qb->createNamedParameter($tripId)));

        $cursor = $qb->executeQuery();
        $result = $cursor->fetch();
        $cursor->closeCursor();

        return (int)($result['count'] ?? 0);
    }

    public function getClusterContent(int|string $clusterId): array
    {
        $tripId = (int)$clusterId;
        if (!Util::isLoggedIn()) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('m.fileid')
           ->from('memories_trip_photos', 'tp')
           ->innerJoin('tp', 'memories', 'm', $qb->expr()->eq('tp.fileid', 'm.fileid'))
           ->where($qb->expr()->eq('tp.trip_id', $qb->createNamedParameter($tripId)));

        $cursor = $qb->executeQuery();
        $fileIds = array_column($cursor->fetchAll(), 'fileid');
        $cursor->closeCursor();

        if (empty($fileIds)) {
            return [];
        }
        
        $baseQuery = $this->tq->getBuilder();
        $baseQuery->select(...TimelineQuery::TIMELINE_SELECT)
               ->from('memories', 'm')
               ->innerJoin('m', 'filecache', 'f', $baseQuery->expr()->eq('m.fileid', 'f.fileid'))
               ->innerJoin('f', 'mimetypes', 'mimetypes', $baseQuery->expr()->eq('f.mimetype', 'mimetypes.id'))
               ->where($baseQuery->expr()->in('m.fileid', $baseQuery->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
               ->orderBy('m.datetaken', 'DESC');

        $cursor = $baseQuery->executeQuery();
        $rows = $cursor->fetchAll();
        $cursor->closeCursor();
        
        return $rows;
    }

    public function getClusterInfo(int|string $clusterId): array
    {
        $tripId = (int)$clusterId;
        if (!Util::isLoggedIn()) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('t.id', 't.name', 't.custom_name', 't.start_date', 't.end_date', 't.timeframe', 't.location')
           ->from('memories_trips', 't')
           ->where($qb->expr()->eq('t.id', $qb->createNamedParameter($tripId)))
           ->andWhere($qb->expr()->eq('t.user_id', $qb->createNamedParameter(Util::getUID())))
           ->setMaxResults(1);

        $cursor = $qb->executeQuery();
        $trip = $cursor->fetch();
        $cursor->closeCursor();

        if (!$trip) {
            return [];
        }

        $places = $this->getTripPlaces($tripId);

        return [
            'id' => $trip['id'],
            'name' => $trip['custom_name'] ?? $trip['name'],
            'count' => $this->getTripPhotoCount($tripId),
            'startDate' => (int)$trip['start_date'],
            'endDate' => (int)$trip['end_date'],
            'timeframe' => $trip['timeframe'] ?? '',
            'location' => $trip['location'] ?? '',
            'places' => $places,
        ];
    }

    private function getTripPlaces(string $tripId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('DISTINCT m.location')
           ->from('memories_trip_photos', 'tp')
           ->innerJoin('tp', 'memories', 'm', $qb->expr()->eq('tp.fileid', 'm.fileid'))
           ->where($qb->expr()->eq('tp.trip_id', $qb->createNamedParameter($tripId)));

        $cursor = $qb->executeQuery();
        $rows = $cursor->fetchAll();
        $cursor->closeCursor();

        $places = [];
        foreach ($rows as $row) {
            if (!empty($row['location'])) {
                $places[] = $row['location'];
            }
        }

        return $places;
    }

    public function getPhotos(string $tripId, ?int $limit = null, ?int $fileid = null): array
    {
        if (!Util::isLoggedIn()) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('m.fileid')
           ->from('memories_trip_photos', 'tp')
           ->innerJoin('tp', 'memories', 'm', $qb->expr()->eq('tp.fileid', 'm.fileid'))
           ->where($qb->expr()->eq('tp.trip_id', $qb->createNamedParameter($tripId)));

        if ($fileid !== null) {
            $qb->andWhere($qb->expr()->eq('m.fileid', $qb->createNamedParameter($fileid)));
        }

        if ($limit !== null && $limit > 0) {
            $qb->setMaxResults($limit);
        }

        $cursor = $qb->executeQuery();
        $fileIds = array_column($cursor->fetchAll(), 'fileid');
        $cursor->closeCursor();
        
        if (empty($fileIds)) {
            return [];
        }

        $detailsQb = $this->db->getQueryBuilder();
        $detailsQb->select('f.fileid', 'f.name')
                  ->from('filecache', 'f')
                  ->where($detailsQb->expr()->in('f.fileid', $detailsQb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));

        $cursor = $detailsQb->executeQuery();
        $result = $cursor->fetchAll();
        $cursor->closeCursor();

        return $result;
    }

    /**
     * Get media items for a trip slideshow
     *
     * @param int $tripId ID of the trip
     * @param string $userId User ID
     * @param int|null $maxVideoDuration Maximum duration for video clips in seconds (null for full videos)
     * @return array Media items for slideshow
     */
    public function getSlideshowMediaForTrip(int $tripId, string $userId, ?int $maxVideoDuration = 10): array
    {
        // Verify trip exists and belongs to the user
        $qb = $this->db->getQueryBuilder();
        $qb->select('t.id', 't.name', 't.custom_name', 't.start_date', 't.end_date')
           ->from('memories_trips', 't')
           ->where($qb->expr()->eq('t.id', $qb->createNamedParameter($tripId)))
           ->andWhere($qb->expr()->eq('t.user_id', $qb->createNamedParameter(Util::getUID())))
           ->setMaxResults(1);

        $cursor = $qb->executeQuery();
        $trip = $cursor->fetch();
        $cursor->closeCursor();

        if (!$trip) {
            return [];
        }

        // Get all file IDs associated with this trip
        $photoQuery = $this->db->getQueryBuilder();
        $photoQuery->select('fileid')
               ->from('memories_trip_photos')
               ->where($photoQuery->expr()->eq('trip_id', $photoQuery->createNamedParameter($tripId)));
        
        $result = $photoQuery->executeQuery();
        $fileIds = array_column($result->fetchAll(), 'fileid');
        $result->closeCursor();
        
        if (empty($fileIds)) {
            return [];
        }
        
        // Get media items from the memories table
        $query = $this->db->getQueryBuilder();
        $query->select('*')
              ->from('memories')
              ->where($query->expr()->in('fileid', $query->createNamedParameter(
                  $fileIds, 
                  \Doctrine\DBAL\Connection::PARAM_INT_ARRAY
               )))
              ->orderBy('datetaken', 'ASC');
        
        $result = $query->executeQuery();
        $mediaItems = [];
        
        while ($row = $result->fetch()) {
            // Determine if this is a video based on available fields
            $isVideo = false;
            if (isset($row['video'])) {
                $isVideo = (bool)$row['video'];
            } else if (isset($row['is_video'])) {
                $isVideo = (bool)$row['is_video'];
            }
            
            // Get duration if available
            $videoDuration = null;
            if ($isVideo && isset($row['duration'])) {
                $videoDuration = (float)$row['duration'];
            }
            
            // For videos, determine if we need to trim and calculate best segment
            $videoSegment = null;
            if ($isVideo && $videoDuration && $maxVideoDuration && $videoDuration > $maxVideoDuration) {
                $videoSegment = $this->getBestVideoSegment((int)$row['fileid'], $videoDuration, $maxVideoDuration);
            }
            
            // Build the media item with required fields
            $item = [
                'id' => (int)$row['fileid'],
                'type' => $isVideo ? 'video' : 'image',
                'datetaken' => $row['datetaken'],
            ];
            
            // Add optional fields if they exist
            if (isset($row['etag'])) $item['etag'] = $row['etag'];
            if (isset($row['path'])) $item['path'] = $row['path'];
            if (isset($row['width'])) $item['w'] = (int)$row['width'];
            if (isset($row['height'])) $item['h'] = (int)$row['height'];
            
            // Add video-specific fields
            if ($isVideo) {
                if (isset($row['duration'])) $item['duration'] = (float)$row['duration'];
                if ($videoSegment) {
                    $item['clipStart'] = $videoSegment['start'];
                    $item['clipDuration'] = $videoSegment['duration'];
                }
            }
            
            $mediaItems[] = $item;
        }
        
        $result->closeCursor();
        
        // Interlace videos and images for better presentation
        return $this->interlaceMediaItems($mediaItems);
    }

    /**
     * Determine the best segment of a video to show
     * 
     * @param int $fileId File ID
     * @param float $fullDuration Full video duration
     * @param int $maxDuration Maximum desired duration
     * @return array Clip information with start and duration
     */
    private function getBestVideoSegment(int $fileId, float $fullDuration, int $maxDuration): array
    {
        // Simple approach: take a segment from the middle
        // More advanced approach would analyze the video content for interesting segments
        
        // If video is only slightly longer than max, just start from beginning
        if ($fullDuration < $maxDuration * 1.5) {
            return [
                'start' => 0,
                'duration' => min($fullDuration, $maxDuration)
            ];
        }
        
        // Take a segment from the middle of the video
        $middlePoint = $fullDuration / 2;
        $start = max(0, $middlePoint - ($maxDuration / 2));
        
        return [
            'start' => $start,
            'duration' => $maxDuration
        ];
    }

    /**
     * Interlace media items by type for alternating videos and images
     *
     * @param array $mediaItems All media items
     * @return array Interlaced media items
     */
    private function interlaceMediaItems(array $mediaItems): array
    {
        // Separate by type
        $images = [];
        $videos = [];
        
        foreach ($mediaItems as $item) {
            if ($item['type'] === 'video') {
                $videos[] = $item;
            } else {
                $images[] = $item;
            }
        }
        
        // If there are no videos or no images, just return all media
        if (empty($videos) || empty($images)) {
            return $mediaItems;
        }
        
        // Interlace videos and images
        $result = [];
        $imageIndex = 0;
        $videoIndex = 0;
        
        while ($imageIndex < count($images) || $videoIndex < count($videos)) {
            // Add a video, then an image
            if ($videoIndex < count($videos)) {
                $result[] = $videos[$videoIndex];
                $videoIndex++;
            }
            
            if ($imageIndex < count($images)) {
                $result[] = $images[$imageIndex];
                $imageIndex++;
            }
        }
        
        return $result;
    }
}
