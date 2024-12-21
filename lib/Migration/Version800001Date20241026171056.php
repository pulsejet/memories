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
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * FIXME Auto-generated migration step: Please modify to your needs!
 */
class Version800001Date20241026171056 extends SimpleMigrationStep
{
    public function __construct(private IDBConnection $dbc) {}

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
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

        if (!$table->hasColumn('uid')) {
            $table->addColumn('uid', 'string', [
                'notnull' => false,
                'length' => 64,
            ]);
        }

        return $schema;
    }

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void
    {
        // create database triggers; this will never throw
        \OCA\Memories\Db\AddMissingIndices::createFilecacheTriggers($output);

        // migrate uid values from filecache
        try {
            $output->info('Migrating values for uid from filecache');

            $platform = $this->dbc->getDatabasePlatform();

            // copy existing parent values from filecache
            if (preg_match('/mysql|mariadb/i', $platform::class)) {
                $this->dbc->executeQuery(
                    'UPDATE *PREFIX*memories AS m
                    JOIN *PREFIX*filecache AS f ON f.fileid = m.fileid
                    JOIN *PREFIX*storages AS s ON f.storage = s.numeric_id
                    SET m.uid = SUBSTRING_INDEX(s.id, \'::\', -1)
                    WHERE s.id LIKE \'home::%\'
                    ',
                );
            } elseif (preg_match('/postgres/i', $platform::class)) {
                $this->dbc->executeQuery(
                    'UPDATE *PREFIX*memories AS m
                     SET uid = split_part(s.id, \'::\', 2)
                     FROM *PREFIX*filecache AS f
                     JOIN *PREFIX*storages AS s ON f.storage = s.numeric_id
                     WHERE f.fileid = m.fileid
                     AND s.id LIKE \'home::%\'
                     ',
                );
            } elseif (preg_match('/sqlite/i', $platform::class)) {
                $this->dbc->executeQuery(
                    'UPDATE memories AS m
                     SET uid = SUBSTR(s.id, INSTR(s.id, \'::\') + 2)
                     FROM filecache AS f
                     JOIN storages AS s ON f.storage = s.numeric_id
                     WHERE f.fileid = m.fileid
                     AND s.id LIKE \'home::%\'
                     ',
                );
            } else {
                throw new \Exception('Unsupported '.$platform::class);
            }

            $output->info('Values for uid migrated successfully');
        } catch (\Exception $e) {
            $output->warning('Failed to copy uid values from fileid: '.$e->getMessage());
            $output->warning('Please run occ memories:index -f');
        }
    }
}
