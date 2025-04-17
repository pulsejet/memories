<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ISession;
use OCA\Memories\Service\Trip\TimeGapTripClusterer;
use OCA\Memories\Service\Trip\HDBSCANTripClusterer;
use OCA\Memories\Service\Trip\TripSeasonDetector;
use OCP\Notification\IManager as NotificationManager;

class DirectTripDetectionService
{
    public const ALGORITHM_TIMEGAP = 'timegap';
    public const ALGORITHM_HDBSCAN = 'hdbscan';
    public const DEFAULT_ALGORITHM = self::ALGORITHM_HDBSCAN;

    public const DEFAULT_MAX_TIME_GAP = 172800;
    public const DEFAULT_MIN_PHOTOS = 5;

    private TimeGapTripClusterer $timeGapClusterer;
    private HDBSCANTripClusterer $hdbscanClusterer;

    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
        private readonly TripSeasonDetector $tripSeasonDetector,
        private readonly NotificationManager $notificationManager,
        private ?ISession $session = null
    ) {
        $this->timeGapClusterer = new TimeGapTripClusterer($logger);
        $this->hdbscanClusterer = new HDBSCANTripClusterer($logger);
    }

    public function detectTrips(
        bool $force = false,
        int $maxTimeGap = self::DEFAULT_MAX_TIME_GAP,
        int $minPhotos = self::DEFAULT_MIN_PHOTOS,
        string $algorithm = self::DEFAULT_ALGORITHM,
        float $timeWeight = 0.7,
        float $locationWeight = 0.3,
        ?string $user = null
    ): int {
        $photos = $this->getAllPhotosForTrips(!$force, $user);
        
        $trips = [];
        
        switch ($algorithm) {
            case self::ALGORITHM_HDBSCAN:
                $trips = $this->hdbscanClusterer->clusterPhotos($photos, $minPhotos, 0.05, $timeWeight, $locationWeight);
                break;
            
            case self::ALGORITHM_TIMEGAP:
            default:
                $trips = $this->timeGapClusterer->clusterPhotos($photos, $maxTimeGap, $minPhotos);
                break;
        }
        
        if ($force) {
            $this->deleteAllTrips($user);
        }
        
        $newTripIds = $this->storeTrips($trips, $user);
        
        if (!empty($newTripIds) && $user !== null) {
            $this->sendTripNotification($newTripIds, $user);
        }
        
        return count($trips);
    }

    private function getAllPhotosForTrips(bool $skipExistingTrips = true, ?string $user = null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('m.fileid', 'm.datetaken', 'm.lat', 'm.lon', 'f.path')
            ->from('memories', 'm')
            ->join('m', 'filecache', 'f', $qb->expr()->eq('m.fileid', 'f.fileid'))
            ->orderBy('m.datetaken', 'DESC')
            ->where($qb->expr()->gt('m.datetaken', $qb->createNamedParameter(0)));
        
        if ($user !== null) {
            $qb->join('f', 'storages', 's', $qb->expr()->eq('f.storage', 's.numeric_id'))
                ->andWhere($qb->expr()->eq('s.id', $qb->createNamedParameter('home::' . $user)));
        }
        
        if ($skipExistingTrips) {
            $subQuery = $this->db->getQueryBuilder();
            $subQuery->select('fileid')
                ->from('memories_trip_photos');
            
            $qb->andWhere("m.fileid NOT IN (" . $subQuery->getSQL() . ")");
        }

        $photos = [];
        $cursor = $qb->executeQuery();
        while ($row = $cursor->fetch()) {
            $splitPath = explode('/', $row['path'], 3);
            if (count($splitPath) < 3) {
                continue;
            }
            if ($splitPath[1] === 'appdata_' || $splitPath[2] === 'thumbnails') {
                continue;
            }

            $photos[] = [
                'fileid' => $row['fileid'],
                'datetaken' => $row['datetaken'],
                'lat' => $row['lat'],
                'lon' => $row['lon'],
                'path' => $row['path'],
            ];
        }
        $cursor->closeCursor();

        return $photos;
    }

    private function getAllTrips(?string $user = null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('t.id', 't.name', 't.start_date', 't.end_date', 't.timeframe', 't.location',
            $qb->createFunction('COUNT(tp.fileid)') . ' as count')
            ->from('memories_trips', 't')
            ->leftJoin('t', 'memories_trip_photos', 'tp', $qb->expr()->eq('t.id', 'tp.trip_id'))
            ->groupBy('t.id', 't.name', 't.start_date', 't.end_date', 't.timeframe', 't.location')
            ->orderBy('t.start_date', 'DESC');
            
        if ($user !== null) {
            $qb->andWhere($qb->expr()->eq('t.user_id', $qb->createNamedParameter($user)));
        }

        $cursor = $qb->executeQuery();
        $trips = $cursor->fetchAll();
        $cursor->closeCursor();

        return $trips;
    }

    private function deleteAllTrips(?string $user = null): void
    {
        $db = $this->db;

        $qb = $db->getQueryBuilder();
        $qb->select('id')
            ->from('memories_trips');
            
        if ($user !== null) {
            $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($user)));
        }
        
        $result = $qb->executeQuery();
        $tripIds = $result->fetchAll(\PDO::FETCH_COLUMN);
        $result->closeCursor();

        if (!empty($tripIds)) {
            $qb = $db->getQueryBuilder();
            $qb->delete('memories_trip_photos')
                ->where($qb->expr()->in('trip_id', $qb->createNamedParameter($tripIds, IQueryBuilder::PARAM_INT_ARRAY)));
            $qb->executeStatement();
        }

        $qb = $db->getQueryBuilder();
        $qb->delete('memories_trips');
        
        if ($user !== null) {
            $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($user)));
        }
        
        $qb->executeStatement();
    }

    private function storeTrips(array $trips, ?string $user = null): array
    {
        $newTripIds = [];
        
        foreach ($trips as $i => $trip) {
            usort($trip, static fn ($a, $b) => $a['datetaken'] <=> $b['datetaken']);

            $begin = $trip[0]['datetaken'];
            $end = $trip[count($trip) - 1]['datetaken'];
            
            if (is_string($begin)) {
                $beginDate = new \DateTime($begin);
                $begin = $beginDate->getTimestamp();
            }
            
            if (is_string($end)) {
                $endDate = new \DateTime($end);
                $end = $endDate->getTimestamp();
            }
            
            $name = $i + 1;
            $distance = 0;
            $tripId = 0;

            if (count($trip) > 1) {
                $distance = $this->calculateDistance($trip);
            }

            $locations = $this->findLocationNames($trip);
            
            $seasonOrHoliday = $this->tripSeasonDetector->identifySeasonOrHoliday($begin, $end, $locations);

            $descriptiveName = $locations;
            if (!empty($seasonOrHoliday)) {
                $descriptiveName = $seasonOrHoliday . ' in ' . $locations;
            }

            $tripId = $this->insertTrip($name, (int)$begin, (int)$end, $distance, $locations, $user);
            $newTripIds[] = [
                'id' => $tripId,
                'name' => $descriptiveName,
                'photoCount' => count($trip)
            ];

            foreach ($trip as $photo) {
                $this->insertTripPhoto($tripId, $photo['fileid']);
            }
        }
        
        return $newTripIds;
    }

    private function insertTrip(int $name, int $begin, int $end, float $distance, string $location = 'Unknown Location', ?string $user = null): int
    {
        $db = $this->db;
        $qb = $db->getQueryBuilder();

        $descriptiveName = $location;
        $seasonOrHoliday = $this->tripSeasonDetector->identifySeasonOrHoliday($begin, $end, $location);
        if (!empty($seasonOrHoliday)) {
            $descriptiveName = $seasonOrHoliday . ' in ' . $location;
        }

        $values = [
            'name' => $qb->createNamedParameter($name),
            'start_date' => $qb->createNamedParameter($begin),
            'end_date' => $qb->createNamedParameter($end),
            'timeframe' => $qb->createNamedParameter($this->formatTimeframe($begin, $end)),
            'location' => $qb->createNamedParameter($location),
            'custom_name' => $qb->createNamedParameter($descriptiveName),
        ];
        
        if ($user !== null) {
            $values['user_id'] = $qb->createNamedParameter($user);
        }

        $qb->insert('memories_trips')
            ->values($values);
        $qb->executeStatement();

        return $db->lastInsertId('memories_trips');
    }

    private function insertTripPhoto(int $tripId, int $fileId): void
    {
        $db = $this->db;
        $qb = $db->getQueryBuilder();

        $qb->insert('memories_trip_photos')
            ->values([
                'trip_id' => $qb->createNamedParameter($tripId),
                'fileid' => $qb->createNamedParameter($fileId),
            ]);
        $qb->executeStatement();
    }

    private function formatTimeframe(int $start, int $end): string
    {
        if ($start <= 0 || $end <= 0) {
            return "Unknown";
        }
        
        $startDate = new \DateTime();
        $startDate->setTimestamp($start);
        
        $endDate = new \DateTime();
        $endDate->setTimestamp($end);
        
        if ($startDate->format('Y') === $endDate->format('Y')) {
            if ($startDate->format('m') === $endDate->format('m')) {
                if ($startDate->format('d') === $endDate->format('d')) {
                    return $startDate->format('M j, Y');
                }
                return $startDate->format('M j') . ' - ' . $endDate->format('j, Y');
            }
            return $startDate->format('M j') . ' - ' . $endDate->format('M j, Y');
        }
        
        return $startDate->format('M j, Y') . ' - ' . $endDate->format('M j, Y');
    }

    private function calculateDistance(array $trip): float
    {
        $distance = 0;
        $prevLat = null;
        $prevLon = null;

        foreach ($trip as $photo) {
            $lat = $photo['lat'];
            $lon = $photo['lon'];

            if ($lat === null || $lon === null || $lat === 0 || $lon === 0) {
                continue;
            }

            if ($prevLat === null || $prevLon === null) {
                $prevLat = $lat;
                $prevLon = $lon;
                continue;
            }

            $distance += $this->haversineDistance((float)$prevLat, (float)$prevLon, (float)$lat, (float)$lon);

            $prevLat = $lat;
            $prevLon = $lon;
        }

        return $distance;
    }

    private function findLocationNames(array $trip): string
    {
        // First check if any photos have forced location names from the HDBSCANTripClusterer
        $forcedNames = [];
        foreach ($trip as $photo) {
            if (isset($photo['force_localized_name']) && isset($photo['force_location_name']) && 
                $photo['force_localized_name'] && !empty($photo['force_location_name'])) {
                $forcedNames[] = $photo['force_location_name'];
            }
        }
        
        // If we have forced names, use the most common one
        if (!empty($forcedNames)) {
            $namesCounts = array_count_values($forcedNames);
            arsort($namesCounts);
            return key($namesCounts);
        }
        
        // Continue with existing OSM-based location detection if no forced names are found
        $geotaggedPhotos = array_filter($trip, function($photo) {
            return !empty($photo['lat']) && !empty($photo['lon']) && 
                   $photo['lat'] != 0 && $photo['lon'] != 0;
        });
        
        if (empty($geotaggedPhotos)) {
            return 'Unknown Location';
        }
        
        $locationsByLevel = [];
        $countryLevel = null;
        
        foreach ($geotaggedPhotos as $photo) {
            $qb = $this->db->getQueryBuilder();
            
            $qb->select('mp.osm_id', 'mp.name', 'mp.admin_level')
                ->from('memories_planet', 'mp')
                ->innerJoin('mp', 'memories_places', 'pl', $qb->expr()->eq('mp.osm_id', 'pl.osm_id'))
                ->where($qb->expr()->eq('pl.fileid', $qb->createNamedParameter($photo['fileid'])))
                ->orderBy('mp.admin_level', 'ASC');
            
            $cursor = $qb->executeQuery();
            while ($row = $cursor->fetch()) {
                $osmId = $row['osm_id'];
                $adminLevel = (int)$row['admin_level'];
                $name = $row['name'];
                
                if ($adminLevel < 0) {
                    continue;
                }
                
                // Identify country level (typically 2)
                if ($adminLevel === 2) {
                    $countryLevel = $adminLevel;
                }
                
                if (!isset($locationsByLevel[$adminLevel])) {
                    $locationsByLevel[$adminLevel] = [];
                }
                
                if (!isset($locationsByLevel[$adminLevel][$osmId])) {
                    $locationsByLevel[$adminLevel][$osmId] = [
                        'name' => $name,
                        'count' => 0,
                    ];
                }
                
                $locationsByLevel[$adminLevel][$osmId]['count']++;
            }
            $cursor->closeCursor();
        }
        
        if (empty($locationsByLevel)) {
            return 'Unknown Location';
        }
        
        $significantLevel = null;
        $maxDiversity = 0;
        $photoCount = count($geotaggedPhotos);
        
        // Reduced threshold for specific locations
        $highCoverageThreshold = 0.55; // Was 0.65
        $baseCoverageThreshold = 0.20; // Was 0.25
        
        // Strongly prioritize city and regional levels
        $cityLevels = [8, 7, 6];
        foreach ($cityLevels as $cityLevel) {
            if (isset($locationsByLevel[$cityLevel])) {
                $highCoverageLocations = array_filter($locationsByLevel[$cityLevel], function($loc) use ($photoCount, $highCoverageThreshold) {
                    return $loc['count'] >= ($photoCount * $highCoverageThreshold);
                });
                
                if (!empty($highCoverageLocations)) {
                    $locationNames = array_column($highCoverageLocations, 'name');
                    $locationCounts = array_column($highCoverageLocations, 'count');
                    
                    $significantLevel = $cityLevel;
                    break;
                }
            }
        }
        
        // Prioritize state/province levels next (usually level 4)
        if ($significantLevel === null && isset($locationsByLevel[4])) {
            $stateLocations = array_filter($locationsByLevel[4], function($loc) use ($photoCount, $baseCoverageThreshold) {
                return $loc['count'] >= ($photoCount * $baseCoverageThreshold);
            });
            
            if (!empty($stateLocations)) {
                $significantLevel = 4;
            }
        }
        
        if ($significantLevel === null) {
            foreach ($locationsByLevel as $level => $locations) {
                // Skip country level unless we have no other options
                if ($level === $countryLevel) {
                    continue;
                }
                
                $significantLocations = array_filter($locations, function($loc) use ($photoCount, $baseCoverageThreshold) {
                    return $loc['count'] >= ($photoCount * $baseCoverageThreshold);
                });
                
                $diversity = count($significantLocations);
                
                $levelBonus = 0;
                if ($level == 8) $levelBonus = 3.0; // Increased from 2.5
                if ($level == 7) $levelBonus = 2.5; // Added level 7
                if ($level == 6) $levelBonus = 2.0;
                if ($level == 5) $levelBonus = 1.0; // Added level 5
                if ($level == 4) $levelBonus = 0.5;
                
                $effectiveDiversity = $diversity + $levelBonus;
                
                if ($effectiveDiversity > $maxDiversity && $diversity >= 1 && $diversity <= 5) {
                    $maxDiversity = $effectiveDiversity;
                    $significantLevel = $level;
                }
            }
        }
        
        // Only fall back to country level if we found nothing else
        if ($significantLevel === null && $countryLevel !== null && isset($locationsByLevel[$countryLevel])) {
            $countryLocations = array_filter($locationsByLevel[$countryLevel], function($loc) use ($photoCount, $baseCoverageThreshold) {
                return $loc['count'] >= ($photoCount * $baseCoverageThreshold);
            });
            
            if (!empty($countryLocations)) {
                $significantLevel = $countryLevel;
            }
        }
        
        // Last fallback - use any available admin level starting from most specific
        if ($significantLevel === null) {
            krsort($locationsByLevel);
            
            foreach ($locationsByLevel as $level => $locations) {
                if (!empty($locations)) {
                    $significantLevel = $level;
                    break;
                }
            }
        }
        
        if ($significantLevel === null && !empty($locationsByLevel)) {
            $significantLevel = array_key_first($locationsByLevel);
        }
        
        if ($significantLevel === null) {
            return 'Unknown Location';
        }
        
        $selectedLocations = $locationsByLevel[$significantLevel];
        
        uasort($selectedLocations, function($a, $b) {
            return $b['count'] <=> $a['count'];
        });
        
        $selectedLocations = array_slice($selectedLocations, 0, 3);
        
        $locationNames = array_column($selectedLocations, 'name');
        
        return !empty($locationNames) ? implode(', ', $locationNames) : 'Unknown Location';
    }

    private function getUserId(): string
    {
        return $this->session->get('user_id') ?? 'admin';
    }

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

        $r = 6371;

        return $r * $c;
    }

    private function sendTripNotification(array $newTripIds, string $user): void
    {
        if (empty($newTripIds)) {
            return;
        }

        $notification = $this->notificationManager->createNotification();
        $notification->setApp('memories')
            ->setUser($user)
            ->setDateTime(new \DateTime())
            ->setObject('trip', 'new_trips');
        
        if (count($newTripIds) === 1) {
            $trip = $newTripIds[0];
            $subject = 'New trip detected: ' . $trip['name'];
            $message = 'A new trip with ' . $trip['photoCount'] . ' photos was detected and added to your Memories.';
            
            $notification->setSubject('new_trip', [
                'tripId' => $trip['id'],
                'tripName' => $trip['name'],
                'photoCount' => $trip['photoCount']
            ])
            ->setMessage('new_trip_message', [
                'message' => $message
            ]);
        } else {
            $tripCount = count($newTripIds);
            $photoCount = array_sum(array_column($newTripIds, 'photoCount'));
            $tripNames = array_column($newTripIds, 'name');
            $tripIds = array_column($newTripIds, 'id');
            
            $subject = $tripCount . ' new trips detected';
            $message = 'Found ' . $tripCount . ' new trips with a total of ' . $photoCount . ' photos in your Memories.';
            
            $notification->setSubject('new_trips', [
                'tripCount' => $tripCount,
                'photoCount' => $photoCount,
                'tripNames' => $tripNames,
                'tripIds' => $tripIds
            ])
            ->setMessage('new_trips_message', [
                'message' => $message
            ]);
        }
        
        // Create action to view trips
        $action = $notification->createAction();
        $action->setLabel('view')
            ->setLink('/apps/memories/trips', 'GET');
        
        $notification->addAction($action);
        
        $this->notificationManager->notify($notification);
    }
}
