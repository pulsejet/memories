<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2023 Varun Patil <radialapps@gmail.com>
 * @author Varun Patil <radialapps@gmail.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version800000Date20240329060325 extends SimpleMigrationStep
{
    public function __construct(private IDBConnection $dbc) {}

    /**
     * @param Closure(): ISchemaWrapper $schemaClosure
     */
    public function preSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {}

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('memories')) {
            throw new \Exception('Memories table does not exist');
        }

        $table = $schema->getTable('memories');

        if (!$table->hasColumn('parent')) {
            $table->addColumn('parent', Types::BIGINT, [
                'notnull' => true,
                'length' => 20,
                'default' => 0,
            ]);
        }

        if (!$table->hasIndex('memories_pdf_idx')) {
            $table->addIndex(['parent', 'dayid', 'fileid'], 'memories_pdf_idx');
        }

        return $schema;
    }

    /**
     * @param Closure(): ISchemaWrapper $schemaClosure
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void
    {
        // create database triggers; this will never throw
        \OCA\Memories\Db\AddMissingIndices::createFilecacheTriggers($output);

        // migrate parent values from filecache
        try {
            $output->info('Migrating values for parent from filecache');

            $platform = $this->dbc->getDatabasePlatform();

            // copy existing parent values from filecache
            if (preg_match('/mysql|mariadb/i', $platform::class)) {
                $this->dbc->executeQuery(
                    'UPDATE *PREFIX*memories m
                    JOIN *PREFIX*filecache f ON m.fileid = f.fileid
                    SET m.parent = f.parent',
                );
            } elseif (preg_match('/postgres/i', $platform::class)) {
                $this->dbc->executeQuery(
                    'UPDATE *PREFIX*memories AS m
                    SET parent = f.parent
                    FROM *PREFIX*filecache AS f
                    WHERE f.fileid = m.fileid',
                );
            } elseif (preg_match('/sqlite/i', $platform::class)) {
                $this->dbc->executeQuery(
                    'UPDATE memories
                    SET parent = (
                        SELECT parent FROM filecache
                        WHERE fileid = memories.fileid)',
                );
            } else {
                throw new \Exception('Unsupported '.$platform::class);
            }

            $output->info('Values for parent migrated successfully');
        } catch (\Exception $e) {
            $output->warning('Failed to copy parent values from filecache: '.$e->getMessage());
            $output->warning('Please run occ memories:index -f');
        }
    }
}
