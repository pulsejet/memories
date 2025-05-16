<?php

declare(strict_types=1);

namespace OCA\Memories\Command;

use OCA\Memories\Service\Video\TripVideoGenerator;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to generate highlight videos for trips
 */
class TripVideoGenerate extends Command
{
    /**
     * Constructor
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
        private readonly TripVideoGenerator $videoGenerator,
        private readonly IUserManager $userManager,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct();
    }

    /**
     * Configure command
     */
    protected function configure(): void
    {
        $this
            ->setName('memories:trips:generate-video')
            ->setDescription('Generate highlight videos for trips')
            ->addArgument(
                'user-id',
                InputArgument::REQUIRED,
                'User ID to generate videos for'
            )
            ->addOption(
                'trip-id',
                't',
                InputOption::VALUE_OPTIONAL,
                'Generate video for a specific trip ID only'
            )
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Generate videos for all trips (if trip-id is not specified)'
            )
            ->addOption(
                'max-images',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Maximum number of images to include in a video',
                12
            )
            ->addOption(
                'image-duration',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Duration each image stays on screen in seconds',
                3.0
            )
            ->addOption(
                'transition-duration',
                'f',
                InputOption::VALUE_OPTIONAL,
                'Duration of fade transitions in seconds',
                1.0
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Force regeneration of videos even if they already exist'
            );
    }

    /**
     * Execute command
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userId = $input->getArgument('user-id');
        $tripId = $input->getOption('trip-id');
        $generateAll = $input->getOption('all');
        $maxImages = (int)$input->getOption('max-images');
        $imageDuration = (float)$input->getOption('image-duration');
        $transitionDuration = (float)$input->getOption('transition-duration');
        $force = $input->getOption('force');

        // Validate arguments
        if ($tripId === null && !$generateAll) {
            $output->writeln('<error>Either --trip-id or --all must be specified</error>');
            return Command::FAILURE;
        }

        // Check if ffmpeg is installed
        exec('which ffmpeg', $out, $returnCode);
        if ($returnCode !== 0) {
            $output->writeln('<error>ffmpeg not found. Please install ffmpeg to generate videos.</error>');
            return Command::FAILURE;
        }

        // Setup user context
        try {
            $user = $this->userManager->get($userId);
            if (!$user) {
                $output->writeln("<error>User $userId not found</error>");
                return Command::FAILURE;
            }
            $this->userSession->setUser($user);
        } catch (\Exception $e) {
            $output->writeln("<error>Error setting up user context: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        try {
            if ($tripId !== null) {
                // Generate video for a specific trip
                $this->generateVideoForTrip(
                    (int)$tripId, 
                    $userId, 
                    $maxImages, 
                    $transitionDuration, 
                    $imageDuration, 
                    $force, 
                    $output
                );
            } else {
                // Generate videos for all trips
                $this->generateVideosForAllTrips(
                    $userId, 
                    $maxImages, 
                    $transitionDuration, 
                    $imageDuration, 
                    $force, 
                    $output
                );
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            $this->logger->error('Trip video generation error: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Generate video for a specific trip
     */
    private function generateVideoForTrip(
        int $tripId, 
        string $userId, 
        int $maxImages, 
        float $transitionDuration, 
        float $imageDuration, 
        bool $force, 
        OutputInterface $output
    ): void {
        // Check if the trip exists
        $trip = $this->getTripById($tripId);
        if (!$trip) {
            throw new \RuntimeException("Trip not found: {$tripId}");
        }

        $output->writeln("<info>Generating video for trip {$tripId}: {$trip['name']} ({$trip['location']})</info>");
        
        try {
            $videoPath = $this->videoGenerator->generateTripVideo(
                $tripId, 
                $userId, 
                $maxImages, 
                $transitionDuration, 
                $imageDuration
            );
            
            $output->writeln("<info>Successfully generated video: {$videoPath}</info>");
            
        } catch (\Exception $e) {
            $output->writeln("<error>Failed to generate video for trip {$tripId}: {$e->getMessage()}</error>");
            throw $e;
        }
    }

    /**
     * Generate videos for all trips
     */
    private function generateVideosForAllTrips(
        string $userId, 
        int $maxImages, 
        float $transitionDuration, 
        float $imageDuration, 
        bool $force, 
        OutputInterface $output
    ): void {
        // Get all trip IDs
        $tripIds = $this->getAllTripIds();
        
        if (empty($tripIds)) {
            $output->writeln('<info>No trips found</info>');
            return;
        }
        
        $output->writeln("<info>Found " . count($tripIds) . " trips. Generating videos...</info>");
        
        $successes = 0;
        $failures = 0;
        
        foreach ($tripIds as $tripId) {
            try {
                $this->generateVideoForTrip(
                    $tripId, 
                    $userId, 
                    $maxImages, 
                    $transitionDuration, 
                    $imageDuration, 
                    $force, 
                    $output
                );
                $successes++;
            } catch (\Exception $e) {
                $failures++;
                // Continue with next trip
            }
        }
        
        $output->writeln("<info>Video generation complete. Successes: {$successes}, Failures: {$failures}</info>");
    }

    /**
     * Get trip details by ID
     */
    private function getTripById(int $tripId): ?array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('memories_trips')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($tripId, \PDO::PARAM_INT)));
        
        $result = $qb->executeQuery();
        $trip = $result->fetch();
        $result->closeCursor();
        
        return $trip ?: null;
    }

    /**
     * Get all trip IDs
     */
    private function getAllTripIds(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('memories_trips')
            ->orderBy('start_date', 'DESC');
        
        $result = $qb->executeQuery();
        $trips = $result->fetchAll();
        $result->closeCursor();
        
        return array_map(fn($trip) => (int)$trip['id'], $trips);
    }
}
