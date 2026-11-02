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

use OCA\Memories\Settings\SystemConfig;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version900007Date20261101000000 extends SimpleMigrationStep
{
    public function __construct(
        private IDBConnection $dbc,
        private SystemConfig $systemConfig,
    ) {}

    /**
     * @param \Closure(): ISchemaWrapper $schemaClosure
     */
    #[\Override]
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void
    {
        // Planet geometry on MySQL moved to SRID 0 and needs a re-import.
        // MariaDB does not enforce the SRID, so existing tables keep working.
        if (IDBConnection::PLATFORM_MYSQL === $this->dbc->getDatabaseProvider(true)) {
            $this->systemConfig->set('memories.gis_type', -1);
        }
    }
}
