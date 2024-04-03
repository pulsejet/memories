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

        $table = $schema->getTable('memories');
        if (!$table->hasColumn('epoch')) {
            $table->addColumn('epoch', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
        }

        return $schema;
    }

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void
    {
        // extracts the epoch value from the EXIF json and stores it in the epoch column
        try {
            // get count of rows to update
            $query = $this->dbc->getQueryBuilder();
            $maxCount = $query
                ->select($query->func()->count('m.fileid'))
                ->from('memories', 'm')
                ->executeQuery()
                ->fetchOne()
            ;
            $output->startProgress((int) $maxCount);

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
                    // try to get the exif string
                    if (!\is_array($row) || !\array_key_exists('exif', $row) || !\is_string($row['exif'])) {
                        continue;
                    }

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
                } finally {
                    $output->advance();
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
            $output->warning('Automatic migration failed: '.$e->getMessage());
            $output->warning('Please run occ memories:index -f');
        }
    }
}
