<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Varun Patil <radialapps@gmail.com>
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

class Version000000Date20220812163631 extends SimpleMigrationStep
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

        if (!$schema->hasTable('memories')) {
            $table = $schema->createTable('memories');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('datetaken', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('fileid', Types::BIGINT, [
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('dayid', Types::INTEGER, [
                'notnull' => true,
            ]);
            $table->addColumn('isvideo', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);

            // Version505001Date20230828155021
            $table->addColumn('mtime', Types::BIGINT, [
                'notnull' => true,
                'length' => 20,
            ]);

            $table->setPrimaryKey(['id']);

            // All these are dropped in Version200000Date20220924015634
            //
            // $table->addColumn('uid', 'string', [
            //     'notnull' => true,
            //     'length' => 64,
            // ]);
            //
            // $table->addIndex(['uid'], 'memories_uid_index');
            // $table->addIndex(['uid', 'dayid'], 'memories_ud_index');
            // $table->addUniqueIndex(['uid', 'fileid'], 'memories_day_uf_ui');
        }

        return $schema;
    }

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {}
}
