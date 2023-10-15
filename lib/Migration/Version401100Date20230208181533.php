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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Auto-generated migration step: Please modify to your needs!
 */
class Version401100Date20230208181533 extends SimpleMigrationStep
{
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

        // Add lat lon and cluster to memories
        if ($schema->hasTable('memories')) {
            $table = $schema->getTable('memories');

            if (!$table->hasColumn('lat')) {
                $table->addColumn('lat', Types::DECIMAL, [
                    'notnull' => false,
                    'default' => null,
                    'precision' => 8,
                    'scale' => 6,
                ]);
            }

            if (!$table->hasColumn('lon')) {
                $table->addColumn('lon', Types::DECIMAL, [
                    'notnull' => false,
                    'default' => null,
                    'precision' => 9,
                    'scale' => 6,
                ]);
            }

            if (!$table->hasColumn('mapcluster')) {
                $table->addColumn('mapcluster', Types::INTEGER, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }

            if (!$table->hasIndex('memories_lat_lon_index')) {
                $table->addIndex(['lat', 'lon'], 'memories_lat_lon_index');
            }

            if (!$table->hasIndex('memories_mapcluster_index')) {
                $table->addIndex(['mapcluster'], 'memories_mapcluster_index');
            }
        }

        // Add clusters table
        if (!$schema->hasTable('memories_mapclusters')) {
            $table = $schema->createTable('memories_mapclusters');
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('point_count', Types::INTEGER, [
                'notnull' => true,
            ]);
            $table->addColumn('lat_sum', Types::FLOAT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('lon_sum', Types::FLOAT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('lat', Types::FLOAT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('lon', Types::FLOAT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('last_update', Types::INTEGER, [
                'notnull' => false,
                'default' => null,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['lat', 'lon'], 'memories_clst_ll_idx');
        }

        return $schema;
    }

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {}
}
