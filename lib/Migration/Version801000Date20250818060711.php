<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Memories\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\DB\Types;

class Version801000Date20250818060711 extends SimpleMigrationStep {
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

        if (!$schema->hasTable('memories_embedded_tags')) {
            $table = $schema->createTable('memories_embedded_tags');
            
            // Add columns
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('tag', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('parent_tag_id', Types::BIGINT, [
                'notnull' => false,
            ]);
            $table->addColumn('path', Types::STRING, [
                'notnull' => true,
                'length' => 1024,
            ]);
            $table->addColumn('level', Types::INTEGER, [
                'notnull' => true,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
                'default' => 'CURRENT_TIMESTAMP',
            ]);
            
            // Add primary key
            $table->setPrimaryKey(['id']);
            
            // Add indexes
            $table->addIndex(['user_id', 'tag'], 'memories_et_user_tag_idx', [], ['lengths' => [null, 255]]);
            
            // Add unique constraint to ensure tags are unique per user
            $table->addUniqueIndex(['user_id', 'path'], 'memories_et_user_path_unique', [], ['lengths' => [null, 768]]);
            
            // Add index on parent_tag_id for better performance
            $table->addIndex(['parent_tag_id'], 'memories_et_parent_id_idx');
        }
        
        // Add foreign key constraint after the table is created
        if ($schema->hasTable('memories_embedded_tags')) {
            $table = $schema->getTable('memories_embedded_tags');
            
            // Check if the foreign key already exists
            $foreignKeys = $table->getForeignKeys();
            $foreignKeyExists = false;
            
            foreach ($foreignKeys as $foreignKey) {
                if ($foreignKey->getLocalColumns() === ['parent_tag_id']) {
                    $foreignKeyExists = true;
                    break;
                }
            }
            
            // Add the foreign key if it doesn't exist
            if (!$foreignKeyExists) {
                $table->addForeignKeyConstraint(
                    $schema->getTable('memories_embedded_tags'), // target table (self-reference)
                    ['parent_tag_id'],        // local columns
                    ['id'],                   // target columns
                    ['onDelete' => 'SET NULL'] // when parent is deleted, set children's parent_tag_id to NULL
                );
            }
        }

        return $schema;
    }

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {}


}
