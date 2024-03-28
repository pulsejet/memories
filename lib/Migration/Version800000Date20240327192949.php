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

class Version800000Date20240327192949 extends SimpleMigrationStep
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

        if (!$schema->hasTable('memories_ss_class')) {
            $table = $schema->createTable('memories_ss_class');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('fileid', Types::BIGINT, [
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('class', Types::INTEGER, [
                'notnull' => true,
            ]);
            $table->addColumn('score', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['fileid'], 'memories_ss_cls_fileid');
            $table->addIndex(['class'], 'memories_ss_cls_cls');
        }

        return $schema;
    }

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {}
}
