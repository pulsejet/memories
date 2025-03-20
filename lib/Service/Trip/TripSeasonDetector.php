<?php

declare(strict_types=1);

namespace OCA\Memories\Service\Trip;

use Psr\Log\LoggerInterface;

/**
 * Service for detecting seasons for trips
 */
class TripSeasonDetector
{
    /**
     * Constructor
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Identify a season based on trip dates
     * Focuses only on general seasons with early/late qualifiers
     * 
     * @param int $start Start timestamp of the trip
     * @param int $end End timestamp of the trip
     * @param string $location Location name of the trip
     * @return string The identified season
     */
    public function identifySeasonOrHoliday(int $start, int $end, string $location): string
    {
        // Create DateTime object for start date
        $startDate = new \DateTime();
        $startDate->setTimestamp($start);
        
        // Extract month and day for comparison
        $month = (int)$startDate->format('m');
        $day = (int)$startDate->format('d');
        
        // Determine season and specificity based on month and day
        $season = '';
        $specificity = '';
        
        // Northern hemisphere seasons
        // Each season spans 3 months
        // First 1/3 of the season is "early", last 1/3 is "late"
        switch ($month) {
            // Winter: December, January, February
            case 12:
            case 1:
            case 2:
                $season = 'Winter';
                if ($month == 12 || ($month == 1 && $day <= 10)) {
                    $specificity = 'Early';
                } else if (($month == 2 && $day >= 20) || ($month == 2 && $day > 15)) {
                    $specificity = 'Late';
                }
                break;
            
            // Spring: March, April, May
            case 3:
            case 4:
            case 5:
                $season = 'Spring';
                if ($month == 3 || ($month == 4 && $day <= 10)) {
                    $specificity = 'Early';
                } else if (($month == 5 && $day >= 20) || ($month == 5 && $day > 15)) {
                    $specificity = 'Late';
                }
                break;
            
            // Summer: June, July, August
            case 6:
            case 7:
            case 8:
                $season = 'Summer';
                if ($month == 6 || ($month == 7 && $day <= 10)) {
                    $specificity = 'Early';
                } else if (($month == 8 && $day >= 20) || ($month == 8 && $day > 15)) {
                    $specificity = 'Late';
                }
                break;
            
            // Fall: September, October, November
            case 9:
            case 10:
            case 11:
                $season = 'Fall';
                if ($month == 9 || ($month == 10 && $day <= 10)) {
                    $specificity = 'Early';
                } else if (($month == 11 && $day >= 20) || ($month == 11 && $day > 15)) {
                    $specificity = 'Late';
                }
                break;
        }
        
        // Combine specificity with season name if applicable
        if (!empty($specificity)) {
            $seasonName = $specificity . ' ' . $season;
        } else {
            $seasonName = $season;
        }
        
        $this->logger->debug("Trip season identified as {$seasonName}");
        return $seasonName;
    }
}
