<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022, Varun Patil <radialapps@gmail.com>
 * @author Varun Patil <radialapps@gmail.com>
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

namespace OCA\Memories\Service;

use OCA\Memories\Settings\SystemConfig;
use OCP\Files\File;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

final class Lens
{
    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger,
        private SystemConfig $systemConfig,
    ) {}

    /**
     * Base URL of the Lens daemon with no trailing slash (empty = disabled).
     */
    public function daemonUrl(): string
    {
        return rtrim($this->systemConfig->get('memories.lens.daemon_url'), '/');
    }

    /**
     * Enqueue a file with the Lens daemon after a successful index.
     *
     * Best-effort: failures are logged and never break indexing.
     */
    public function enqueue(File $file): void
    {
        try {
            $base = $this->daemonUrl();
            if ('' === $base) {
                return;
            }

            if (!str_starts_with($file->getMimeType(), 'image/')) {
                return;
            }

            $this->clientService->newClient()->post($base.'/v1/index', [
                'json' => [
                    'fileid' => $file->getId(),
                    'parent_id' => $file->getParent()->getId(),
                ],
                'timeout' => 2,
                'nextcloud' => ['allow_local_address' => true],
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Lens enqueue failed: '.$e->getMessage());
        }
    }
}
