<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OC\DB\SchemaWrapper;
use OCA\Memories\Settings\SystemConfig;
use OCP\IDBConnection;
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

        // Add missing name index on filecache
        // https://github.com/nextcloud/server/pull/44586
        if ($schema->hasTable('filecache')) {
            $table = $schema->getTable('filecache');

            // If the PR is merged, remove the index we created
            // In that case remove this block after some time
            $hasOwn = $table->hasIndex('fs_name_hash');
            $hasOur = $table->hasIndex('memories_name_hash');

            if (!$hasOwn && !$hasOur) {
                // Add our index because none exists
                $table->addIndex(['name'], 'memories_name_hash');
                $ops[] = 'filecache::memories_name_hash';
            } elseif ($hasOwn && $hasOur) {
                // Remove our index because filecache has one
                $table->dropIndex('memories_name_hash');
                $ops[] = '-filecache::memories_name_hash';
            }
        }

        // Migrate
        if (\count($ops) > 0) {
            $output->info('Updating external table schema: '.implode(', ', $ops));
            $connection->migrateToSchema($schema->getWrappedSchema());
        } else {
            $output->info('External table schema seem up to date');
        }

        // Create triggers in this step too
        self::createFilecacheTriggers($output);

        return $schema;
    }

    /**
     * Create filecache triggers.
     */
    public static function createFilecacheTriggers(IOutput $output): void
    {
        $connection = \OC::$server->get(IDBConnection::class);
        $platform = $connection->getDatabasePlatform();

        // Trigger to update parent from filecache
        try {
            if (preg_match('/mysql|mariadb/i', $platform::class)) {
                // MySQL has no upsert for triggers
                $connection->executeQuery('DROP TRIGGER IF EXISTS memories_fcu_trg;');

                // Create the trigger again
                $connection->executeQuery(
                    'CREATE TRIGGER memories_fcu_trg
                    AFTER UPDATE ON *PREFIX*filecache
                    FOR EACH ROW
                        UPDATE *PREFIX*memories
                        SET parent = NEW.parent
                        WHERE fileid = NEW.fileid;',
                );
            } elseif (preg_match('/postgres/i', $platform::class)) {
                // Postgres requres a function to do the update
                // Note: when dropping, the function should be dropped
                // with CASCADE to remove the trigger as well
                $connection->executeQuery(
                    'CREATE OR REPLACE FUNCTION memories_fcu_fun()
                    RETURNS TRIGGER AS $$
                    BEGIN
                        UPDATE *PREFIX*memories
                        SET parent = NEW.parent
                        WHERE fileid = NEW.fileid;
                        RETURN NEW;
                    END;
                    $$ LANGUAGE plpgsql;',
                );

                // Create the trigger for the function
                $connection->executeQuery(
                    'CREATE OR REPLACE TRIGGER memories_fcu_trg
                    AFTER UPDATE ON *PREFIX*filecache
                    FOR EACH ROW
                    EXECUTE FUNCTION memories_fcu_fun();',
                );
            } elseif (preg_match('/sqlite/i', $platform::class)) {
                // Exactly the same as MySQL except for the BEGIN and END
                $connection->executeQuery('DROP TRIGGER IF EXISTS memories_fcu_trg;');
                $connection->executeQuery(
                    'CREATE TRIGGER memories_fcu_trg
                    AFTER UPDATE ON *PREFIX*filecache
                    FOR EACH ROW
                    BEGIN
                        UPDATE *PREFIX*memories
                        SET parent = NEW.parent
                        WHERE fileid = NEW.fileid;
                    END;',
                );
            } else {
                throw new \Exception('Unsupported database platform: '.$platform::class);
            }

            $output->info('Recreated filecache trigger with: '.$platform::class);
            SystemConfig::set('memories.db.triggers.fcu', true);
        } catch (\Throwable $e) {
            $output->warning('Failed to create filecache trigger (compatibility mode will be used): '.$e->getMessage());
            SystemConfig::set('memories.db.triggers.fcu', false);
        }
    }
}
