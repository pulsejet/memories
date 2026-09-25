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
use OCP\Security\ICrypto;

final class ServiceManager
{
    public const SERVICE_TOKEN_HEADER = 'X-Memories-Service-Token';
    public const SERVICE_TOKEN_TTL = 24 * 60 * 60; // 24 hours

    public function __construct(
        private IUserSession $userSession,
        private IRootFolder $rootFolder,
        private IUserMountCache $mountCache,
        private SystemConfig $systemConfig,
        private ICrypto $crypto,
    ) {}

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

    /**
     * Provision a short-lived opaque token for go-vod to fetch one file.
     *
     * Encrypted with the instance secret; go-vod sends it back untouched.
     */
    public function mintServiceToken(int $fileid): string
    {
        $payload = json_encode([
            'fileid' => $fileid,
            'expiry' => time() + self::SERVICE_TOKEN_TTL,
        ]);

        return $this->crypto->encrypt($payload ?: 'failure');
    }

    /**
     * Resolve a file from a provisioned service token.
     *
     * All failures share one status without revealing file existence,
     * with a distinct message per cause.
     */
    public function getServiceTokenFile(string $token, int $fileid): File
    {
        try {
            $data = json_decode($this->crypto->decrypt($token), true);
        } catch (\Exception) {
            throw Exceptions::Forbidden('service token decrypt failed');
        }

        if (!\is_array($data)) {
            throw Exceptions::Forbidden('no service token payload');
        }

        if ((int) ($data['expiry'] ?? 0) < time()) {
            throw Exceptions::Forbidden('service token expired');
        }

        if ((int) ($data['fileid'] ?? 0) !== $fileid) {
            throw Exceptions::Forbidden('service token fileid mismatch');
        }

        return $this->getServiceFile($fileid);
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
