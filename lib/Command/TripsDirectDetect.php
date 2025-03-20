<?php

declare(strict_types=1);

namespace OCA\Memories\Command;

use OCA\Memories\Service\DirectTripDetectionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Direct trip detection command that skips the events step
 */
class TripsDirectDetect extends Command
{
    public function __construct(
        private DirectTripDetectionService $tripDetectionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('memories:trips:direct-detect')
            ->setDescription('Detect trips by directly clustering photos')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force processing of all photos')
            ->addOption('max-time-gap', 't', InputOption::VALUE_REQUIRED, 'Maximum time gap (in seconds) between photos in the same trip', DirectTripDetectionService::DEFAULT_MAX_TIME_GAP)
            ->addOption('min-photos', 'm', InputOption::VALUE_REQUIRED, 'Minimum number of photos to form a trip', DirectTripDetectionService::DEFAULT_MIN_PHOTOS)
            ->addOption('algorithm', 'a', InputOption::VALUE_REQUIRED,
                'Clustering algorithm to use: timegap (default) or hdbscan',
                DirectTripDetectionService::DEFAULT_ALGORITHM)
            ->addOption('time-weight', null, InputOption::VALUE_REQUIRED,
                'Weight for time dimension in HDBSCAN (higher values give more importance to time)',
                0.7)
            ->addOption('location-weight', null, InputOption::VALUE_REQUIRED,
                'Weight for location dimension in HDBSCAN (higher values give more importance to location)',
                0.3)
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED,
                'Run trip detection only for this user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->getHelper('formatter');
        $io = new SymfonyStyle($input, $output);

        $force = $input->getOption('force');
        $maxTimeGap = $input->getOption('max-time-gap') ?? DirectTripDetectionService::DEFAULT_MAX_TIME_GAP;
        $minPhotos = $input->getOption('min-photos') ?? DirectTripDetectionService::DEFAULT_MIN_PHOTOS;
        $algorithm = $input->getOption('algorithm') ?? DirectTripDetectionService::DEFAULT_ALGORITHM;
        $timeWeight = $input->getOption('time-weight') ?? 0.7;
        $locationWeight = $input->getOption('location-weight') ?? 0.3;
        $user = $input->getOption('user');

        // Validate algorithm
        $validAlgorithms = [DirectTripDetectionService::ALGORITHM_TIMEGAP, DirectTripDetectionService::ALGORITHM_HDBSCAN];
        if (!in_array($algorithm, $validAlgorithms)) {
            $io->error("Invalid algorithm: {$algorithm}. Valid algorithms are: " . implode(', ', $validAlgorithms));
            return 1;
        }

        // Show a message with the parameters
        $io->note("Running direct trip detection with the following parameters:");
        $io->listing([
            "Algorithm: {$algorithm}",
            "Force: " . ($force ? 'Yes (process all photos)' : 'No (only process new photos)'),
            "Max time gap: {$maxTimeGap} seconds (" . round($maxTimeGap / 86400, 1) . " days)",
            "Min photos: {$minPhotos}",
            "Time weight: {$timeWeight}" . ($algorithm === DirectTripDetectionService::ALGORITHM_HDBSCAN ? '' : ' (only used with HDBSCAN)'),
            "Location weight: {$locationWeight}" . ($algorithm === DirectTripDetectionService::ALGORITHM_HDBSCAN ? '' : ' (only used with HDBSCAN)'),
            "User: " . ($user ? $user : 'All users'),
        ]);

        $start = microtime(true);

        try {
            $trips = $this->tripDetectionService->detectTrips(
                $force,
                $maxTimeGap,
                $minPhotos,
                $algorithm,
                (float)$timeWeight,
                (float)$locationWeight,
                $user
            );
            $io->success("Found {$trips} trips in " . round(microtime(true) - $start, 2) . " seconds");
            return 0;
        } catch (\Exception $e) {
            $io->error("Error detecting trips: {$e->getMessage()}");
            return 1;
        }
    }
}
