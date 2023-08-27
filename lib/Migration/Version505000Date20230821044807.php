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

/**
 * Auto-generated migration step: Please modify to your needs!
 */
class Version505000Date20230821044807 extends SimpleMigrationStep
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
    }

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('memories');
        $table->addColumn('epoch', Types::BIGINT, [
            'notnull' => true,
            'default' => 0,
        ]);

        return $schema;
    }

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void
    {
        // extracts the epoch value from the EXIF json and stores it in the epoch column
        try {
            // get the required records
            $result = $this->dbc->getQueryBuilder()
                ->select('m.id', 'm.exif')
                ->from('memories', 'm')
                ->executeQuery()
            ;
            $count = 0;

            // iterate the memories table and update the epoch column
            $this->dbc->beginTransaction();
            while ($row = $result->fetch()) {
                try {
                    // get the epoch from the exif data
                    $exif = json_decode($row['exif'], true);
                    if (!\is_array($exif) || !\array_key_exists('DateTimeEpoch', $exif)) {
                        continue;
                    }

                    // get epoch from exif if available
                    if ($epoch = (int) $exif['DateTimeEpoch']) {
                        // update the epoch column
                        $query = $this->dbc->getQueryBuilder();
                        $query->update('memories')
                            ->set('epoch', $query->createNamedParameter($epoch))
                            ->where($query->expr()->eq('id', $query->createNamedParameter((int) $row['id'], \PDO::PARAM_INT)))
                            ->executeStatement()
                        ;

                        // increment the counter
                        ++$count;
                    }
                } catch (\Exception $e) {
                    continue;
                }

                // commit every 50 rows
                if (0 === $count % 50) {
                    $this->dbc->commit();
                    $this->dbc->beginTransaction();
                }
            }

            // commit the remaining rows
            $this->dbc->commit();

            // close the cursor
            $result->closeCursor();
        } catch (\Exception $e) {
            error_log('Automatic migration failed: '.$e->getMessage());
            error_log('Please run occ memories:index -f');
        }
    }
}
