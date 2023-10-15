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
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Auto-generated migration step: Please modify to your needs!
 */
class Version200000Date20220924015634 extends SimpleMigrationStep
{
    /** @var IDBConnection */
    private $dbc;

    public function __construct(IDBConnection $dbc)
    {
        $this->dbc = $dbc;
    }

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    public function preSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if ($schema->hasTable('memories')) {
            $table = $schema->getTable('memories');
            if ($table->hasColumn('uid')) {
                $sql = $this->dbc->getDatabasePlatform()->getTruncateTableSQL('`*PREFIX*memories`', false);
                $this->dbc->executeStatement($sql);
            }
        }
    }

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

        // Drop all indicies with UID
        if ($table->hasIndex('memories_uid_index')) {
            $table->dropIndex('memories_uid_index');
        }

        if ($table->hasIndex('memories_ud_index')) {
            $table->dropIndex('memories_ud_index');
        }

        if ($table->hasIndex('memories_day_uf_ui')) {
            $table->dropIndex('memories_day_uf_ui');
        }

        // Drop UID column
        if ($table->hasColumn('uid')) {
            $table->dropColumn('uid');
        }

        // Add new indicies
        if (!$table->hasIndex('memories_dayid_index')) {
            $table->addIndex(['dayid'], 'memories_dayid_index');
        }

        if (!$table->hasIndex('memories_fileid_index')) {
            $table->addUniqueIndex(['fileid'], 'memories_fileid_index');
        }

        return $schema;
    }

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {}
}
