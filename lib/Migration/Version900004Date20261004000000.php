<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Varun Patil <radialapps@gmail.com>
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

final class Version900004Date20261004000000 extends SimpleMigrationStep
{
    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    #[\Override]
    public function preSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {}

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    #[\Override]
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Clusters first for readability; faces references them logically only.
        if (!$schema->hasTable('memories_lens_clusters')) {
            $table = $schema->createTable('memories_lens_clusters');
            $table->addColumn('id', Types::BIGINT, [
                'notnull' => true,
                // Daemon-minted uint63, never 0; no autoincrement.
                'autoincrement' => false,
                'unsigned' => false,
            ]);
            $table->addColumn('owner_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('person_id', Types::BIGINT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('embed_version', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('created', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['owner_id'], 'mem_lens_clst_owner_idx');
            $table->addIndex(['person_id'], 'mem_lens_clst_person_idx');
        }

        // Per-viewer names; logical person existence is inferred from clusters.
        if (!$schema->hasTable('memories_lens_persons')) {
            $table = $schema->createTable('memories_lens_persons');
            $table->addColumn('person_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('viewer_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->setPrimaryKey(['person_id', 'viewer_id']);
            $table->addIndex(['viewer_id'], 'mem_lens_prs_viewer_idx');
        }

        if (!$schema->hasTable('memories_lens_faces')) {
            $table = $schema->createTable('memories_lens_faces');
            $table->addColumn('id', Types::BIGINT, [
                'notnull' => true,
                // Daemon-minted uint63, re-provided on reuse; no autoincrement.
                'autoincrement' => false,
                'unsigned' => false,
            ]);
            $table->addColumn('fileid', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('x', Types::FLOAT, [
                'notnull' => true,
            ]);
            $table->addColumn('y', Types::FLOAT, [
                'notnull' => true,
            ]);
            $table->addColumn('w', Types::FLOAT, [
                'notnull' => true,
            ]);
            $table->addColumn('h', Types::FLOAT, [
                'notnull' => true,
            ]);
            $table->addColumn('det_score', Types::FLOAT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('embed_version', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('cluster_id', Types::BIGINT, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('next_try', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('retries', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('frame_ms', Types::INTEGER, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['fileid'], 'mem_lens_faces_fileid_idx');
            $table->addIndex(['cluster_id'], 'mem_lens_faces_cluster_idx');
            $table->addIndex(['embed_version', 'cluster_id', 'next_try'], 'mem_lens_faces_due_idx');
        }

        return $schema;
    }

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    #[\Override]
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {}
}
