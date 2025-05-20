<?php
declare(strict_types=1);
namespace OCA\Memories\Command;

use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to clean all trip-related tables
 */
class TripsCleanup extends Command
{
    public function __construct(
        private IDBConnection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('memories:trips:cleanup')
            ->setDescription('Clean all trip-related tables, removing all trips and their associated data');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $db = $this->db;

        try {
            // Get initial counts for reporting
            $tripCount = $this->getTableCount('memories_trips');
            $photoAssociationCount = $this->getTableCount('memories_trip_photos');

            // Define all trip-related tables
            $tripTables = [
                'memories_trip_photos',
                'memories_trips'
            ];

            // Truncate all trip-related tables in the correct order
            // Starting with dependent tables first
            foreach ($tripTables as $table) {
                $qb = $db->getQueryBuilder();
                $qb->delete($table);
                $qb->executeStatement();
                $io->note("Truncated table: {$table}");
            }

            $io->success("Successfully cleaned all trip data: removed {$tripCount} trips and {$photoAssociationCount} photo associations");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Error cleaning trip data: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Get count of records in a table
     */
    private function getTableCount(string $tableName): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($tableName);
        return (int) $qb->executeQuery()->fetchOne();
    }
}
