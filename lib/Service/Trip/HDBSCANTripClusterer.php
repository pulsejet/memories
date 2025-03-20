<?php

declare(strict_types=1);

namespace OCA\Memories\Service\Trip;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

class HDBSCANTripClusterer
{
    /**
     * Minimum number of photos for a split by location
     */
    private const MIN_PHOTOS_LABEL_SPLIT = 15;

    /**
     * Minimum number of days before today for a trip to be considered "finished"
     */
    private const MIN_DAYS_BEFORE_TODAY = 3.0;

    /**
     * Time-location distance constant: how many kilometers equal one hour in importance
     * Higher values make time more important than location
     */
    private const TIME_LOCATION_EQUIVALENCE = 30; // km per hour

    /**
     * Trip boundary threshold in the normalized distance space
     * Lower values create more trip splits (more granular trips)
     */
    private const TRIP_BOUNDARY_THRESHOLD = 0.25;

    /**
     * Grid size for geo-clustering (in degrees)
     */
    private const GEO_GRID_SIZE = 0.05;

    /**
     * Number of nearest neighbors to use for core distance calculation
     */
    private const MIN_CORE_NEIGHBORS = 5;

    /**
     * Constructor
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Cluster photos into trips using HDBSCAN approach
     * 
     * @param array $photos Array of photos to cluster
     * @param int $minPhotos Minimum number of photos for a trip
     * @param float $threshold Threshold for trip splitting
     * @param float $timeWeight Weight of time component (0.0-1.0)
     * @param float $locationWeight Weight of location component (0.0-1.0)
     * @return array Array of trips, each containing an array of photos
     */
    public function clusterPhotos(array $photos, int $minPhotos, float $threshold = 0.05, float $timeWeight = 0.7, float $locationWeight = 0.3): array
    {
        if (empty($photos)) {
            return [];
        }

        // Sort photos by time
        usort($photos, function ($a, $b) {
            return $a['datetaken'] - $b['datetaken'];
        });
        
        $this->logger->debug("Starting HDBSCAN clustering with " . count($photos) . " photos");
        $this->logger->debug("Parameters: threshold={$threshold}, timeWeight={$timeWeight}, locationWeight={$locationWeight}");
        
        // Separate photos with location data from those without
        $photosWithLocation = [];
        $photosWithoutLocation = [];
        
        foreach ($photos as $photo) {
            if (!empty($photo['lat']) && !empty($photo['lon']) && 
                $photo['lat'] != 0 && $photo['lon'] != 0) {
                $photosWithLocation[] = $photo;
            } else {
                $photosWithoutLocation[] = $photo;
            }
        }
        
        $this->logger->debug(count($photosWithLocation) . " photos have location data, " . 
                          count($photosWithoutLocation) . " photos do not have location data");
        
        // If we don't have enough photos with location data, fall back to using all photos
        if (count($photosWithLocation) < $minPhotos) {
            $this->logger->debug("Not enough photos with location data, using all photos for clustering");
            
            // Pre-compute core distances for each photo (HDBSCAN step 1)
            $coreDistances = $this->calculateCoreDistances($photos, self::MIN_CORE_NEIGHBORS, $timeWeight, $locationWeight);
            
            // Find clusters using a HDBSCAN-inspired approach based on mutual reachability distance
            $trips = $this->findClustersWithReachabilityDistance($photos, $coreDistances, $minPhotos, self::TRIP_BOUNDARY_THRESHOLD, $timeWeight, $locationWeight);
            
            // Process trips to ensure they meet minimum requirements
            $trips = $this->filterTrips($trips, $minPhotos);
        } else {
            // Only use photos with location data for initial clustering
            $this->logger->debug("Using only photos with location data for initial clustering");
            
            // Pre-compute core distances for photos with location
            $coreDistances = $this->calculateCoreDistances($photosWithLocation, self::MIN_CORE_NEIGHBORS, $timeWeight, $locationWeight);
            
            // Find clusters using photos with location
            $trips = $this->findClustersWithReachabilityDistance($photosWithLocation, $coreDistances, $minPhotos, self::TRIP_BOUNDARY_THRESHOLD, $timeWeight, $locationWeight);
            
            // Process trips to ensure they meet minimum requirements
            $trips = $this->filterTrips($trips, $minPhotos);
            
            // If we have photos without location and trips were detected
            if (!empty($photosWithoutLocation) && !empty($trips)) {
                $this->logger->debug("Assigning " . count($photosWithoutLocation) . " photos without location data to trips");
                $trips = $this->assignPhotosWithoutLocationToTrips($photosWithoutLocation, $trips);
            }
        }
        
        $this->logger->debug("Found " . count($trips) . " trips after clustering");
        
        return $trips;
    }
    
    /**
     * Calculate core distances for each photo (Step 1 of HDBSCAN)
     * 
     * @param array $photos Array of photos
     * @param int $neighbors Number of neighbors to use for core distance
     * @param float $timeWeight Weight for time component
     * @param float $locationWeight Weight for location component
     * @return array Core distances indexed by photo ID
     */
    private function calculateCoreDistances(array $photos, int $neighbors, float $timeWeight, float $locationWeight): array
    {
        $coreDistances = [];
        $n = count($photos);
        
        foreach ($photos as $i => $photo) {
            $distances = [];
            
            // Calculate distance to all other photos
            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) continue;
                
                $distance = $this->calculateDistance($photo, $photos[$j], $timeWeight, $locationWeight);
                $distances[] = $distance;
            }
            
            // Sort distances and get the k-th nearest neighbor distance
            sort($distances);
            $coreDistances[$photo['fileid']] = isset($distances[$neighbors - 1]) 
                ? $distances[$neighbors - 1] 
                : PHP_FLOAT_MAX;
        }
        
        return $coreDistances;
    }
    
    /**
     * Find clusters using mutual reachability distance (Step 2-4 of HDBSCAN)
     * 
     * @param array $photos Array of photos
     * @param array $coreDistances Core distances for each photo
     * @param int $minPhotos Minimum photos per trip
     * @param float $threshold Boundary threshold
     * @param float $timeWeight Time weight
     * @param float $locationWeight Location weight
     * @return array Array of trips
     */
    private function findClustersWithReachabilityDistance(array $photos, array $coreDistances, int $minPhotos, float $threshold, float $timeWeight, float $locationWeight): array
    {
        $n = count($photos);
        $trips = [];
        $currentTrip = [$photos[0]];
        
        for ($i = 1; $i < $n; $i++) {
            $previousPhoto = $photos[$i - 1];
            $currentPhoto = $photos[$i];
            
            // Calculate mutual reachability distance (HDBSCAN core concept)
            $directDistance = $this->calculateDistance($previousPhoto, $currentPhoto, $timeWeight, $locationWeight);
            $coreDistPrev = $coreDistances[$previousPhoto['fileid']] ?? PHP_FLOAT_MAX;
            $coreDistCurrent = $coreDistances[$currentPhoto['fileid']] ?? PHP_FLOAT_MAX;
            
            // Mutual reachability distance is the max of direct distance and core distances
            $mutualReachabilityDistance = max($directDistance, $coreDistPrev, $coreDistCurrent);
            
            // Log for debugging
            $timeDiff = ($currentPhoto['datetaken'] - $previousPhoto['datetaken']) / 3600; // hours
            $locDistance = $this->getDistanceBetweenPhotos($previousPhoto, $currentPhoto);
            
            // Convert timestamp to date strings for logging
            $fromDate = date('Y-m-d H:i', (int)$previousPhoto['datetaken']);
            $toDate = date('Y-m-d H:i', (int)$currentPhoto['datetaken']);
            
            $this->logger->debug("Photo {$i}: {$fromDate} → {$toDate}, " . 
                "Time: {$timeDiff}h, Distance: {$locDistance}km, " .
                "Direct: {$directDistance}, Mutual: {$mutualReachabilityDistance}");
            
            // If mutual reachability distance exceeds threshold, start a new trip
            if ($mutualReachabilityDistance > $threshold) {
                $this->logger->debug("Starting new trip at photo {$i} due to mutual reachability distance {$mutualReachabilityDistance} > {$threshold}");
                
                if (count($currentTrip) > 0) {
                    $trips[] = $currentTrip;
                }
                
                $currentTrip = [$currentPhoto];
            } else {
                $currentTrip[] = $currentPhoto;
            }
        }
        
        // Add the last trip if it's not empty
        if (count($currentTrip) > 0) {
            $trips[] = $currentTrip;
        }
        
        return $trips;
    }
    
    /**
     * Filter trips to ensure they meet minimum requirements
     * 
     * @param array $trips Array of trips
     * @param int $minPhotos Minimum photos per trip
     * @return array Filtered trips
     */
    private function filterTrips(array $trips, int $minPhotos): array
    {
        $filteredTrips = [];
        
        foreach ($trips as $trip) {
            if (count($trip) >= $minPhotos) {
                $filteredTrips[] = $trip;
            } else {
                $this->logger->debug("Discarding trip with " . count($trip) . " photos (< {$minPhotos})");
            }
        }
        
        return $filteredTrips;
    }
    
    /**
     * Calculate distance between two photos based on time and location
     * 
     * @param array $photo1 First photo
     * @param array $photo2 Second photo
     * @param float $timeWeight Weight of time component (0.0-1.0)
     * @param float $locationWeight Weight of location component (0.0-1.0)
     * @return float Normalized distance
     */
    private function calculateDistance($photo1, $photo2, $timeWeight, $locationWeight): float
    {
        // If either photo doesn't have location data, rely only on time difference
        if (empty($photo1['lat']) || empty($photo1['lon']) || 
            empty($photo2['lat']) || empty($photo2['lon'])) {
            // Normalize by treating 6 hours as a significant gap
            $timeDiffHours = abs($photo2['datetaken'] - $photo1['datetaken']) / 3600;
            return $timeDiffHours / 6;
        }
        
        // Get time difference in hours
        $timeDiffHours = abs($photo2['datetaken'] - $photo1['datetaken']) / 3600; 
        
        // Get geographic distance in kilometers
        $geoDistance = $this->getDistanceBetweenPhotos($photo1, $photo2);
        
        // Normalize time difference by converting to equivalent kilometers
        $timeDistanceEquivalent = $timeDiffHours * self::TIME_LOCATION_EQUIVALENCE;
        
        // Calculate weighted distance using both time and location components
        $normalizedDistance = ($timeWeight * $timeDistanceEquivalent + $locationWeight * $geoDistance) 
                            / ($timeWeight + $locationWeight);
        
        return $normalizedDistance;
    }
    
    /**
     * Get distance between two photos in kilometers
     */
    private function getDistanceBetweenPhotos(array $photo1, array $photo2): float
    {
        // If either photo doesn't have coordinates, return 0
        if (empty($photo1['lat']) || empty($photo1['lon']) || empty($photo2['lat']) || empty($photo2['lon']) ||
            $photo1['lat'] === 0 || $photo1['lon'] === 0 || $photo2['lat'] === 0 || $photo2['lon'] === 0) {
            return 0;
        }
        
        return $this->haversineDistance(
            (float)$photo1['lat'], 
            (float)$photo1['lon'], 
            (float)$photo2['lat'], 
            (float)$photo2['lon']
        );
    }
    
    /**
     * Calculate haversine distance between two points in kilometers
     */
    private function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);

        $dlat = $lat2 - $lat1;
        $dlon = $lon2 - $lon1;

        $a = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        // Earth's radius in kilometers
        $r = 6371;

        return $r * $c;
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
    
    /**
     * Assign photos without location data to trips based on their timestamps
     * 
     * @param array $photosWithoutLocation Photos without location data
     * @param array $trips Trips containing photos with location data
     * @return array Updated trips with all photos assigned
     */
    private function assignPhotosWithoutLocationToTrips(array $photosWithoutLocation, array $trips): array
    {
        // First, determine the time range for each trip
        $tripTimeRanges = [];
        foreach ($trips as $tripIndex => $trip) {
            $startTime = PHP_INT_MAX;
            $endTime = 0;
            
            foreach ($trip as $photo) {
                $startTime = min($startTime, $photo['datetaken']);
                $endTime = max($endTime, $photo['datetaken']);
            }
            
            // Add some buffer to trip time ranges (1 hour before and after)
            $tripTimeRanges[$tripIndex] = [
                'start' => $startTime - 3600,
                'end' => $endTime + 3600,
            ];
        }
        
        // Sort photos without location by time
        usort($photosWithoutLocation, function ($a, $b) {
            return $a['datetaken'] - $b['datetaken'];
        });
        
        // Assign each photo without location to the appropriate trip
        foreach ($photosWithoutLocation as $photo) {
            $bestTripIndex = null;
            $bestTimeDiff = PHP_INT_MAX;
            
            // Find the closest trip in time
            foreach ($tripTimeRanges as $tripIndex => $timeRange) {
                // If photo falls within a trip's time range, assign it to that trip
                if ($photo['datetaken'] >= $timeRange['start'] && $photo['datetaken'] <= $timeRange['end']) {
                    $bestTripIndex = $tripIndex;
                    break;
                }
                
                // Otherwise, calculate the closest trip in time
                $timeDiffStart = abs($photo['datetaken'] - $timeRange['start']);
                $timeDiffEnd = abs($photo['datetaken'] - $timeRange['end']);
                $timeDiff = min($timeDiffStart, $timeDiffEnd);
                
                if ($timeDiff < $bestTimeDiff) {
                    $bestTimeDiff = $timeDiff;
                    $bestTripIndex = $tripIndex;
                }
            }
            
            // Add the photo to the closest trip
            if ($bestTripIndex !== null) {
                $trips[$bestTripIndex][] = $photo;
                $this->logger->debug("Assigned photo ID " . $photo['fileid'] . " to trip " . ($bestTripIndex + 1));
            }
        }
        
        // Re-sort all trips by time to maintain chronological order
        foreach ($trips as &$trip) {
            usort($trip, function ($a, $b) {
                return $a['datetaken'] - $b['datetaken'];
            });
        }
        
        return $trips;
    }
}
