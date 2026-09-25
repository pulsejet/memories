<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Varun Patil <radialapps@gmail.com>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\Service;

use OCA\Memories\Exceptions;
use OCA\Memories\HttpResponseException;
use OCA\Memories\Settings\SystemConfig;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserSession;

final class ServiceManager
{
    public function __construct(
        private IUserSession $userSession,
        private IRootFolder $rootFolder,
        private IUserMountCache $mountCache,
        private SystemConfig $systemConfig,
    ) {}

    public function isVodServiceAccount(): bool
    {
        return $this->isServiceAccount('memories.vod.service_user');
    }

    public function isLensServiceAccount(): bool
    {
        return $this->isServiceAccount('memories.lens.service_user');
    }

    /**
     * Only the configured service account may proceed.
     *
     * Runs before any file lookup, so failures never reveal file existence.
     */
    public function guardServiceAccount(string $key): void
    {
        $serviceUser = $this->systemConfig->get($key);

        $user = $this->userSession->getUser();
        if (null === $user) {
            throw new HttpResponseException(new DataResponse([
                'message' => 'Unauthorized',
            ], Http::STATUS_UNAUTHORIZED));
        }

        if ('' === $serviceUser || $user->getUID() !== $serviceUser) {
            throw new HttpResponseException(new DataResponse([
                'message' => 'Forbidden',
            ], Http::STATUS_FORBIDDEN));
        }
    }

    public function guardLensServiceAccount(): void
    {
        $this->guardServiceAccount('memories.lens.service_user');
    }

    public function guardVodServiceAccount(): void
    {
        $this->guardServiceAccount('memories.vod.service_user');
    }

    public function getServiceFile(int $fileid): File
    {
        if ($fileid <= 0) {
            throw Exceptions::NotFoundFile($fileid);
        }

        try {
            // getById only sees set-up mounts, which are the caller's;
            // find a user whose mounts contain this file and look up inside theirs.
            $userFolder = $this->rootFolder->getUserFolder($this->getFileOwner($fileid));
            $nodes = $userFolder->getById($fileid);
        } catch (NotFoundException $e) {
            throw Exceptions::NotFoundFile($fileid);
        }

        foreach ($nodes as $node) {
            if ($node instanceof File) {
                return $node;
            }
        }

        throw Exceptions::NotFoundFile($fileid);
    }

    private function isServiceAccount(string $key): bool
    {
        $serviceUser = $this->systemConfig->get($key);
        if ('' === $serviceUser) {
            return false;
        }

        return $this->userSession->getUser()?->getUID() === $serviceUser;
    }

    /**
     * UID of a user whose mounts contain this fileid.
     *
     * Any mount serves identical bytes; the auth guard already ran,
     * so this reveals nothing about the file to the caller.
     */
    private function getFileOwner(int $fileid): string
    {
        foreach ($this->mountCache->getMountsForFileId($fileid) as $info) {
            return $info->getUser()->getUID();
        }

        throw Exceptions::NotFoundFile($fileid);
    }
}
