<?php

declare(strict_types=1);

/**
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\Listeners;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCP\DB\Events\AddMissingIndicesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Migration\IOutput;

/**
 * Indices on tables of other apps and the server, added by `occ db:add-missing-indices`.
 *
 * @template-implements IEventListener<Event|AddMissingIndicesEvent>
 */
final class AddMissingIndicesListener implements IEventListener
{
    public function __construct(private Connection $connection) {}

    #[\Override]
    public function handle(Event $event): void
    {
        if (!$event instanceof AddMissingIndicesEvent) {
            return;
        }

        // Speed up CTE lookup for subdirectories (also ensured by the repair step)
        $event->addMissingIndex('filecache', 'memories_parent_mimetype', ['parent', 'mimetype']);

        // Speed up tag lookups by type
        $event->addMissingIndex('systemtag_object_mapping', 'memories_type_tagid', ['objecttype', 'systemtagid']);

        // Speed up album lookups by file (e.g. shared album files)
        $event->addMissingIndex('photos_albums_files', 'memories_paf_file_id', ['file_id']);
    }

    /**
     * Add indices that queries cannot work without (e.g. index hints).
     * Run from the repair step; the rest are added by occ db:add-missing-indices.
     */
    public function addRequired(IOutput $output): void
    {
        $schema = new SchemaWrapper($this->connection);
        $changed = false;

        $table = $schema->getTable('filecache');
        if (!$table->hasIndex('memories_parent_mimetype')) {
            $output->info('Adding filecache index memories_parent_mimetype');
            $table->addIndex(['parent', 'mimetype'], 'memories_parent_mimetype');
            $changed = true;
        }

        if ($changed) {
            $this->connection->migrateToSchema($schema->getWrappedSchema());
        }
    }
}
