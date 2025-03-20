<?php

declare(strict_types=1);

namespace OCA\Memories\Service\Trip;

use Psr\Log\LoggerInterface;

class TimeGapTripClusterer
{
    /**
     * Minimum days from today before a trip is considered finished
     */
    private const MIN_DAYS_BEFORE_TODAY = 5;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function clusterPhotos(array $photos, int $maxTimeGap, int $minPhotos): array
    {
        $this->logger->debug('Starting TimeGap clustering with maxTimeGap=' . $maxTimeGap . ' seconds');

        $trips = [];
        $currentTrip = [];
        $lastPhoto = null;
        $currentTripLocation = null;

        foreach ($photos as $photo) {
            if ($lastPhoto === null) {
                $currentTrip[] = $photo;
                $lastPhoto = $photo;
                $currentTripLocation = $photo['location'] ?? 'Unknown Location';
                continue;
            }

            $timeGap = $photo['datetaken'] - $lastPhoto['datetaken'];
            
            $locationChange = false;
            $photoLocation = $photo['location'] ?? 'Unknown Location';
            $lastPhotoLocation = $lastPhoto['location'] ?? 'Unknown Location';
            
            if ($lastPhotoLocation !== 'Unknown Location' && $photoLocation !== 'Unknown Location' &&
                $lastPhotoLocation !== $photoLocation) {
                $locationChange = !$this->areLocationsRelated($lastPhotoLocation, $photoLocation);
                if ($locationChange) {
                    $this->logger->debug('Significant location change detected: ' . $lastPhotoLocation . ' -> ' . $photoLocation);
                }
            }
            
            $this->logger->debug('Time gap between photos: ' . $timeGap . ' seconds (' . ($timeGap / 86400) . ' days)');

            $startNewTrip = false;
            $reasonForNewTrip = '';
            
            if ($timeGap > $maxTimeGap) {
                $startNewTrip = true;
                $reasonForNewTrip = 'Time gap > maxTimeGap';
            }
            
            if ($locationChange) {
                $startNewTrip = true;
                $reasonForNewTrip = 'Significant location change';
            }
            
            if ($startNewTrip) {
                $this->logger->debug($reasonForNewTrip . '. Starting new trip.');
                
                if (count($currentTrip) >= $minPhotos) {
                    // Only include the trip if it's not too recent (might be unfinished)
                    if ($this->isTripFinished($currentTrip)) {
                        $trips[] = $currentTrip;
                        $this->logger->debug('Added trip with ' . count($currentTrip) . ' photos in location ' . $currentTripLocation);
                    } else {
                        $this->logger->debug('Skipped recent trip with ' . count($currentTrip) . ' photos. Trip might be unfinished.');
                    }
                } else {
                    $this->logger->debug('Discarded trip with ' . count($currentTrip) . ' photos (minimum is ' . $minPhotos . ').');
                }

                $currentTrip = [$photo];
                $currentTripLocation = $photoLocation;
            } else {
                $currentTrip[] = $photo;
            }

            $lastPhoto = $photo;
        }

        if (count($currentTrip) >= $minPhotos) {
            // Check the final trip as well
            if ($this->isTripFinished($currentTrip)) {
                $trips[] = $currentTrip;
                $this->logger->debug('Added final trip with ' . count($currentTrip) . ' photos in location ' . $currentTripLocation);
            } else {
                $this->logger->debug('Skipped recent final trip with ' . count($currentTrip) . ' photos. Trip might be unfinished.');
            }
        }
        
        $this->logger->debug('TimeGap clustering completed. Found ' . count($trips) . ' trips.');
        
        return $trips;
    }
    
    private function areLocationsRelated(string $loc1, string $loc2): bool
    {
        return str_contains($loc1, $loc2) || str_contains($loc2, $loc1);
    }

    /**
     * Check if a trip is considered "finished" (most recent photo is at least MIN_DAYS_BEFORE_TODAY days old)
     * 
     * @param array $tripPhotos Array of photos in a trip
     * @return bool True if the trip is finished, false if it's recent and might be unfinished
     */
    private function isTripFinished(array $tripPhotos): bool
    {
        if (empty($tripPhotos)) {
            return false;
        }

        // Get the most recent photo in the trip
        $lastPhotoTimestamp = max(array_map(fn($photo) => $photo['datetaken'], $tripPhotos));
        
        // Calculate days between most recent photo and today
        $now = time();
        $daysSinceLastPhoto = ($now - $lastPhotoTimestamp) / 86400; // 86400 seconds in a day
        
        $isFinished = $daysSinceLastPhoto >= self::MIN_DAYS_BEFORE_TODAY;
        
        if (!$isFinished) {
            $this->logger->debug("Trip contains photos from {$daysSinceLastPhoto} days ago, which is less than minimum " . self::MIN_DAYS_BEFORE_TODAY . " days needed to consider it finished");
        }
        
        return $isFinished;
    }
}
