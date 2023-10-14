<?php

namespace OCA\Memories\Db;

use OC\DB\SchemaWrapper;
use OCP\Migration\IOutput;

class AddMissingIndices
{
    /**
     * Add missing indices to the database schema.
     */
    public static function run(IOutput $output): SchemaWrapper
    {
        $connection = \OC::$server->get(\OC\DB\Connection::class);
        $schema = new SchemaWrapper($connection);

        // Should migrate at end
        $ops = [];

        // Speed up CTE lookup for subdirectories
        if ($schema->hasTable('filecache')) {
            $table = $schema->getTable('filecache');

            if (!$table->hasIndex('memories_parent_mimetype')) {
                $table->addIndex(['parent', 'mimetype'], 'memories_parent_mimetype');
                $ops[] = 'filecache::memories_parent_mimetype';
            }
        }

        // Add index on systemtag_object_mapping to speed up the query
        if ($schema->hasTable('systemtag_object_mapping')) {
            $table = $schema->getTable('systemtag_object_mapping');

            if (!$table->hasIndex('memories_type_tagid')) {
                $table->addIndex(['objecttype', 'systemtagid'], 'memories_type_tagid');
                $ops[] = 'systemtag_object_mapping::memories_type_tagid';
            }
        }

        // Add index on recognize detections for file id to speed up joins
        if ($schema->hasTable('recognize_face_detections')) {
            $table = $schema->getTable('recognize_face_detections');

            // Starting at some version, recognize ships an index on file_id
            // In that case, we *remove* the memories index if it exists
            $hasOwn = $table->hasIndex('recognize_facedet_file');
            $hasOur = $table->hasIndex('memories_file_id');

            if (!$hasOwn && !$hasOur) {
                // Add our index because none exists
                $table->addIndex(['file_id'], 'memories_file_id');
                $ops[] = 'recognize_face_detections::memories_file_id';
            } elseif ($hasOwn && $hasOur) {
                // Remove our index because recognize has one
                $table->dropIndex('memories_file_id');
                $ops[] = '-recognize_face_detections::memories_file_id';
            }
        }

        // Migrate
        if (\count($ops) > 0 && null !== $connection) {
            $output->info('Updating external table schema: '.implode(', ', $ops));
            $connection->migrateToSchema($schema->getWrappedSchema());
        } elseif (null === $connection) {
            $output->warning('No database connection, skipping external table schema update');
        } else {
            $output->info('External table schema seem up to date');
        }

        return $schema;
    }
}
