<?php

namespace OCA\Memories\Db;

use OC\DB\SchemaWrapper;

class AddMissingIndices
{
    /**
     * Add missing indices to the database schema.
     */
    public static function run()
    {
        $connection = \OC::$server->get(\OC\DB\Connection::class);
        $schema = new SchemaWrapper($connection);

        // Should migrate at end
        $shouldMigrate = false;

        // Add index on systemtag_object_mapping to speed up the query
        if ($schema->hasTable('systemtag_object_mapping')) {
            $table = $schema->getTable('systemtag_object_mapping');

            if (!$table->hasIndex('memories_type_tagid')) {
                $table->addIndex(['objecttype', 'systemtagid'], 'memories_type_tagid');
                $shouldMigrate = true;
            }
        }

        // Add index on recognize detections for file id to speed up joins
        if ($schema->hasTable('recognize_face_detections')) {
            $table = $schema->getTable('recognize_face_detections');

            if (!$table->hasIndex('memories_file_id')) {
                $table->addIndex(['file_id'], 'memories_file_id');
                $shouldMigrate = true;
            }
        }

        // Migrate
        if ($shouldMigrate && null !== $connection) {
            $connection->migrateToSchema($schema->getWrappedSchema());
        }

        return $schema;
    }
}
