<?php

declare(strict_types=1);

namespace OCA\Memories\Migration;

use OCA\Memories\Service\TripDetectionService;
use Psr\Log\LoggerInterface;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to setup trip tables, columns, and trip photos table
 */
class Version900002Date20250320120723 extends SimpleMigrationStep
{
    public function __construct(
        protected IDBConnection $connection,
        protected LoggerInterface $logger,
    ) {}

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Create the trips table if it doesn't exist
        $this->createTripsTable($schema);

        // Add columns to existing trips table if needed
        $this->addColumnsToTripsTable($schema);

        // Create the trip_photos table for direct photo association
        $this->createTripPhotosTable($schema);

        return $schema;
    }

    /**
     * Create the memories_trips table
     */
    private function createTripsTable(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('memories_trips')) {
            return;
        }

        $table = $schema->createTable('memories_trips');
        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('name', Types::STRING, [
            'notnull' => true,
            'length' => 255,
        ]);
        $table->addColumn('custom_name', Types::STRING, [
            'notnull' => false,
            'length' => 255,
            'default' => null,
        ]);
        $table->addColumn('start_date', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('end_date', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('timeframe', Types::STRING, [
            'notnull' => false,
            'length' => 255,
            'default' => null,
        ]);
        $table->addColumn('location', Types::STRING, [
            'notnull' => false,
            'length' => 255,
            'default' => null,
        ]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['start_date'], 'memories_trips_startdate_idx');
        $table->addIndex(['end_date'], 'memories_trips_enddate_idx');
        $table->addIndex(['user_id'], 'memories_trips_userid_idx');
    }

    /**
     * Add columns to the memories_trips table if it already exists
     */
    private function addColumnsToTripsTable(ISchemaWrapper $schema): void
    {
        if (!$schema->hasTable('memories_trips')) {
            return;
        }

        $table = $schema->getTable('memories_trips');

        // Add user_id column if it doesn't exist
        if (!$table->hasColumn('user_id')) {
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
                'default' => '',
            ]);
            $table->addIndex(['user_id'], 'memories_trips_userid_idx');
        }

        // Add timeframe column if it doesn't exist
        if (!$table->hasColumn('timeframe')) {
            $table->addColumn('timeframe', Types::STRING, [
                'notnull' => false,
                'length' => 100,
            ]);
        }

        // Add location column if it doesn't exist
        if (!$table->hasColumn('location')) {
            $table->addColumn('location', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
        }
    }

    /**
     * Create the memories_trip_photos table for direct photo-to-trip association
     */
    private function createTripPhotosTable(ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('memories_trip_photos')) {
            return;
        }

        $table = $schema->createTable('memories_trip_photos');
        $table->addColumn('trip_id', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('fileid', Types::BIGINT, [
            'notnull' => true,
            'length' => 20,
        ]);
        $table->addUniqueIndex(['trip_id', 'fileid'], 'trip_photos_unique');
        $table->addIndex(['trip_id'], 'trip_photos_trip_id_idx');
        $table->addIndex(['fileid'], 'trip_photos_fileid_idx');
        $table->addIndex(['user_id'], 'trip_photos_userid_idx');

        // Add foreign key to trips table
        if ($schema->hasTable('memories_trips')) {
            $table->addForeignKeyConstraint(
                $schema->getTable('memories_trips'),
                ['trip_id'],
                ['id'],
                ['onDelete' => 'CASCADE']
            );
        }
    }

    /**
     * @param IOutput $output
     * @param \Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void
    {
    }
}
