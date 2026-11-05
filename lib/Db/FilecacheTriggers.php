<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\Settings\SystemConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;

final class FilecacheTriggers
{
    public function __construct(
        private IDBConnection $connection,
        private SystemConfig $systemConfig,
    ) {}

    /**
     * Create filecache triggers.
     */
    public function create(IOutput $output): void
    {
        $provider = $this->connection->getDatabaseProvider(true);

        // Trigger to update parent from filecache
        try {
            if (IDBConnection::PLATFORM_MYSQL === $provider) {
                // MySQL does not support REPLACE TRIGGER; just skip if it exists.
                $this->connection->executeQuery(
                    'CREATE TRIGGER IF NOT EXISTS memories_fcu_trg
                    AFTER UPDATE ON *PREFIX*filecache
                    FOR EACH ROW
                        UPDATE *PREFIX*memories
                        SET parent = NEW.parent
                        WHERE fileid = NEW.fileid;',
                );
            } elseif (IDBConnection::PLATFORM_MARIADB === $provider) {
                // Safer than MySQL, always ensures that the trigger is current.
                $this->connection->executeQuery(
                    'CREATE OR REPLACE TRIGGER memories_fcu_trg
                    AFTER UPDATE ON *PREFIX*filecache
                    FOR EACH ROW
                        UPDATE *PREFIX*memories
                        SET parent = NEW.parent
                        WHERE fileid = NEW.fileid;',
                );
            } elseif (IDBConnection::PLATFORM_POSTGRES === $provider) {
                // Postgres requires a function to do the update
                // Note: when dropping, the function should be dropped
                // with CASCADE to remove the trigger as well
                $this->connection->executeQuery(
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
                $this->connection->executeQuery(
                    'CREATE OR REPLACE TRIGGER memories_fcu_trg
                    AFTER UPDATE ON *PREFIX*filecache
                    FOR EACH ROW
                    EXECUTE FUNCTION memories_fcu_fun();',
                );
            } elseif (IDBConnection::PLATFORM_SQLITE === $provider) {
                // Exactly the same as MySQL except for the BEGIN and END
                $this->connection->executeQuery(
                    'CREATE TRIGGER IF NOT EXISTS memories_fcu_trg
                    AFTER UPDATE ON *PREFIX*filecache
                    FOR EACH ROW
                    BEGIN
                        UPDATE *PREFIX*memories
                        SET parent = NEW.parent
                        WHERE fileid = NEW.fileid;
                    END;',
                );
            } else {
                throw new \Exception('Unsupported database platform: '.$provider);
            }

            $output->info('Recreated filecache trigger with: '.$provider);
            $this->systemConfig->set('memories.db.triggers.fcu', true);
        } catch (\Throwable $e) {
            $output->warning('Failed to create filecache trigger (compatibility mode will be used): '.$e->getMessage());
            $this->systemConfig->set('memories.db.triggers.fcu', false);
        }
    }
}
