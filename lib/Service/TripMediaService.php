<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCA\Memories\AppInfo\Application;

/**
 * Service for selecting and retrieving trip media files
 * Used by both TripVideoGenerator and TripVideoController
 */
class TripMediaService
{
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Get media files for a trip
     *
     * @param int $tripId Trip ID
     * @param string $userId User ID
     * @param int $maxItems Maximum number of items to return (or 0 for percentage-based selection)
     * @param int|null $maxVideoDuration Maximum video duration in seconds (null for no limit)
     * @param float|null $maxPercentage Maximum percentage of trip media to include (null for no percentage limit)
     * @param int $minItems Minimum number of items to include, regardless of percentage
     * @return array Array of media files
     */
    public function getTripMedia(
        int $tripId, 
        string $userId, 
        int $maxItems = 15,
        ?int $maxVideoDuration = null,
        ?float $maxPercentage = 0.2,
        int $minItems = 15
    ): array {
        $this->logger->info("Fetching media files for trip id={$tripId}, userId={$userId}, maxItems={$maxItems}, maxPercentage={$maxPercentage}, minItems={$minItems}");
        
        // Get file IDs associated with this trip from memories_trip_photos
        $qb = $this->db->getQueryBuilder();
        $qb->select('f.fileid', 'f.path', 'm.datetaken', 'f.mimetype', 'm.w', 'm.h', 'm.video_duration')
           ->from('memories_trip_photos', 'tp')
           ->innerJoin('tp', 'filecache', 'f', 'tp.fileid = f.fileid')
           ->innerJoin('f', 'memories', 'm', 'f.fileid = m.fileid')
           ->where($qb->expr()->eq('tp.trip_id', $qb->createNamedParameter($tripId, \PDO::PARAM_INT)))
           ->orderBy('m.datetaken', 'ASC');
        
        // First get a count of all files in this trip
        $countQb = $this->db->getQueryBuilder();
        $countQb->select($countQb->func()->count('*', 'count'))
               ->from('memories_trip_photos', 'tp')
               ->where($countQb->expr()->eq('tp.trip_id', $countQb->createNamedParameter($tripId, \PDO::PARAM_INT)));
        
        $result = $countQb->executeQuery();
        $totalCount = (int)$result->fetchOne();
        $result->closeCursor();
        
        // Calculate how many items to fetch based on percentage and minimum
        $percentageLimit = $maxPercentage !== null ? max($minItems, ceil($totalCount * $maxPercentage)) : PHP_INT_MAX;
        $effectiveLimit = $maxItems > 0 ? min($maxItems, $percentageLimit) : $percentageLimit;
        
        $this->logger->info("Trip {$tripId} has {$totalCount} total media items, using limit of {$effectiveLimit} items");
        
        if ($effectiveLimit > 0) {
            // We'll get more items than we need and then select representative ones
            $qb->setMaxResults($effectiveLimit * 3);
        }
        
        $result = $qb->executeQuery();
        $allFiles = $result->fetchAll();
        $result->closeCursor();
        
        if (empty($allFiles)) {
            $this->logger->info("No media files found for trip {$tripId}");
            return [];
        }
        
        // Separate images and videos
        $images = [];
        $videos = [];
        $videoMimes = Application::VIDEO_MIMES;
        
        foreach ($allFiles as &$file) {
            // Get mimetype string if we have a numeric ID
            if (isset($file['mimetype']) && is_numeric($file['mimetype'])) {
                $mimeQuery = $this->db->getQueryBuilder();
                $mimeQuery->select('mimetype')
                   ->from('mimetypes')
                   ->where($mimeQuery->expr()->eq('id', $mimeQuery->createNamedParameter($file['mimetype'], \PDO::PARAM_INT)));
                
                $mimeResult = $mimeQuery->executeQuery();
                $mimetype = $mimeResult->fetchOne();
                $mimeResult->closeCursor();
                
                if ($mimetype) {
                    $file['mimetype_str'] = $mimetype;
                }
            }
            
            $isVideo = false;
            $mimetype = $file['mimetype_str'] ?? '';
            
            // Determine if this is a video
            if (isset($file['is_video'])) {
                $isVideo = (bool)$file['is_video'];
            } else if (strpos($mimetype, 'video/') === 0) {
                $isVideo = true;
            } else if (!empty($mimetype) && in_array($mimetype, $videoMimes)) {
                $isVideo = true;
            }
            
            // Filter videos by duration if needed
            if ($isVideo && $maxVideoDuration !== null && 
                isset($file['video_duration']) && (float)$file['video_duration'] > $maxVideoDuration) {
                continue; // Skip this video as it's too long
            }
            
            // Add file to appropriate array
            if ($isVideo) {
                $videos[] = $file;
            } else {
                $images[] = $file;
            }
        }
        
        $this->logger->debug("Trip {$tripId} has " . count($images) . " images and " . count($videos) . " videos");
        
        // Return the representative media
        return $this->selectRepresentativeMedia($images, $videos, $effectiveLimit);
    }

    /**
     * Select representative media from images and videos
     * 
     * @param array $images Array of image files
     * @param array $videos Array of video files
     * @param int $maxItems Maximum number of items to return
     * @return array Selected media files
     */
    public function selectRepresentativeMedia(array $images, array $videos, int $maxItems): array
    {
        $totalItems = count($images) + count($videos);
        
        if ($totalItems <= $maxItems) {
            // If we have few enough media items, use them all but interlace them
            return $this->interlaceMediaByType($images, $videos);
        }
        
        // Determine ratio based on available media
        // We want to include at least one video if available
        $videoCount = min(count($videos), max(1, (int)($maxItems * 0.3)));
        $imageCount = min(count($images), $maxItems - $videoCount);
        
        // If we have too few images, include more videos
        if ($imageCount < ($maxItems - $videoCount) && count($videos) > $videoCount) {
            $videoCount = min(count($videos), $maxItems - $imageCount);
        }
        
        // Select representative images
        $selectedImages = [];
        if ($imageCount > 0 && count($images) > 0) {
            $imageStep = count($images) / $imageCount;
            
            // Always include first and last image
            if ($imageCount >= 2) {
                $selectedImages[] = $images[0];
                
                for ($i = 1; $i < $imageCount - 1; $i++) {
                    $index = (int)round($i * $imageStep);
                    $selectedImages[] = $images[$index];
                }
                
                $selectedImages[] = $images[count($images) - 1];
            } else {
                // If we can only include one image, use the middle one
                $selectedImages[] = $images[(int)(count($images) / 2)];
            }
        }
        
        // Select representative videos
        $selectedVideos = [];
        if ($videoCount > 0 && count($videos) > 0) {
            $videoStep = count($videos) / $videoCount;
            
            for ($i = 0; $i < $videoCount; $i++) {
                $index = min(count($videos) - 1, (int)round($i * $videoStep));
                $selectedVideos[] = $videos[$index];
            }
        }
        
        // Interlace videos and images for a more balanced presentation
        return $this->interlaceMediaByType($selectedImages, $selectedVideos);
    }

    /**
     * Interlace videos and images to create an alternating pattern
     * 
     * @param array $images Array of image files
     * @param array $videos Array of video files
     * @return array Interlaced media files
     */
    public function interlaceMediaByType(array $images, array $videos): array
    {
        $result = [];
        
        // If we have only one type, just return it
        if (empty($images)) {
            return $videos;
        }
        if (empty($videos)) {
            return $images;
        }
        
        // Get the count of each type
        $imageCount = count($images);
        $videoCount = count($videos);
        $totalCount = $imageCount + $videoCount;
        
        // Calculate how often we should insert a video
        // If videos are more numerous, we'll do the inverse calculation
        if ($videoCount <= $imageCount) {
            $videoInterval = max(1, (int)round($totalCount / $videoCount));
            
            $videoIndex = 0;
            $imageIndex = 0;
            
            for ($i = 0; $i < $totalCount; $i++) {
                if ($i > 0 && $i % $videoInterval === 0 && $videoIndex < $videoCount) {
                    $result[] = $videos[$videoIndex++];
                } else if ($imageIndex < $imageCount) {
                    $result[] = $images[$imageIndex++];
                } else if ($videoIndex < $videoCount) {
                    $result[] = $videos[$videoIndex++];
                }
            }
        } else {
            $imageInterval = max(1, (int)round($totalCount / $imageCount));
            
            $videoIndex = 0;
            $imageIndex = 0;
            
            for ($i = 0; $i < $totalCount; $i++) {
                if ($i > 0 && $i % $imageInterval === 0 && $imageIndex < $imageCount) {
                    $result[] = $images[$imageIndex++];
                } else if ($videoIndex < $videoCount) {
                    $result[] = $videos[$videoIndex++];
                } else if ($imageIndex < $imageCount) {
                    $result[] = $images[$imageIndex++];
                }
            }
        }
        
        // Sort the result by datetaken to maintain chronological order
        usort($result, function($a, $b) {
            return strcmp($a['datetaken'] ?? '', $b['datetaken'] ?? '');
        });
        
        return $result;
    }
}
