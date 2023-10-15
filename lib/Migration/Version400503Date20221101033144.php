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
class Version400503Date20221101033144 extends SimpleMigrationStep
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
    public function preSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {}

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('memories');

        // This is a hack to speed up the tags queries.
        // The problem is objectid in systemtags is VARCHAR(64) while fileid in
        // filecache and memories is BIGINT(20), so a join is extremely slow,
        // because the entire tags table must be scanned for the conversion.
        if (!$table->hasColumn('objectid')) {
            $table->addColumn('objectid', 'string', [
                'notnull' => true,
                'length' => 64,
                'default' => '0', // set to real value in postSchemaChange
            ]);
        }

        if (!$table->hasIndex('memories_objectid_index')) {
            $table->addIndex(['objectid'], 'memories_objectid_index');
        }

        return $schema;
    }

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void
    {
        // Update oc_memories to set objectid equal to fileid for all rows
        $this->dbc->executeQuery('UPDATE *PREFIX*memories SET objectid = CAST(fileid AS CHAR(64))');
    }
}
