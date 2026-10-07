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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version900005Date20261007000000 extends SimpleMigrationStep
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

        // Lookup by fileid + mtime when finding files to index (#1737)
        $failures = $schema->getTable('memories_failures');
        if (!$failures->hasIndex('memories_fail_fid_mt_idx')) {
            $failures->addIndex(['fileid', 'mtime'], 'memories_fail_fid_mt_idx');
        }

        // Same lookup for live photo video parts
        $livephoto = $schema->getTable('memories_livephoto');
        if (!$livephoto->hasIndex('memories_lp_fid_mt_idx')) {
            $livephoto->addIndex(['fileid', 'mtime'], 'memories_lp_fid_mt_idx');
        }

        return $schema;
    }

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    #[\Override]
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {}
}
