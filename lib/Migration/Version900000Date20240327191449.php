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

class Version900000Date20240327191449 extends SimpleMigrationStep
{
    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    public function preSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void
    {
        // Patch doctrine to use float instead of double
        \Doctrine\DBAL\Types\Type::overrideType(Types::FLOAT, RealFloatType::class);
    }

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('memories_ss_vectors')) {
            $table = $schema->createTable('memories_ss_vectors');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('fileid', Types::BIGINT, [
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('mtime', Types::BIGINT, [
                'notnull' => true,
                'length' => 20,
            ]);

            // Create embedding columns
            $size = 768;
            for ($i = 0; $i < $size; ++$i) {
                $table->addColumn('v'.$i, Types::FLOAT, [
                    'notnull' => true,
                    'default' => 0,
                ]);
            }

            $table->setPrimaryKey(['id']);
            $table->addIndex(['fileid', 'mtime'], 'memories_ss_vec_fileid');
        }

        return $schema;
    }

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void
    {
        // Revert doctrine patch
        \Doctrine\DBAL\Types\Type::overrideType(Types::FLOAT, \Doctrine\DBAL\Types\FloatType::class);
    }
}

class RealFloatType extends \Doctrine\DBAL\Types\FloatType
{
    public function getSQLDeclaration(array $column, \Doctrine\DBAL\Platforms\AbstractPlatform $platform)
    {
        if (preg_match('/mysql|mariadb/i', $platform::class)) {
            return 'FLOAT';
        }

        // https://www.postgresql.org/docs/current/datatype-numeric.html
        if (preg_match('/postgres/i', $platform::class)) {
            return 'REAL';
        }

        return parent::getSQLDeclaration($column, $platform);
    }
}
