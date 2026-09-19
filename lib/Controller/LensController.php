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

namespace OCA\Memories\Controller;

use OCA\Memories\Exceptions;
use OCA\Memories\HttpResponseException;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\NotFoundException;

final class LensController extends GenericApiController
{
    /**
     * Serve raw file bytes to the Lens service account by fileid.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function file(int $fileid): Http\Response
    {
        return Util::guardEx(function () use ($fileid) {
            $this->guardServiceAccount();

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

            $file = null;
            foreach ($nodes as $node) {
                if ($node instanceof File) {
                    $file = $node;

                    break;
                }
            }

            if (null === $file) {
                throw Exceptions::NotFoundFile($fileid);
            }

            $handle = $file->fopen('rb');
            if (false === $handle) {
                throw new \Exception("Failed to open file {$fileid}");
            }

            $response = new StreamResponse($handle);
            $response->addHeader('Content-Type', $file->getMimeType());
            if ($etag = $file->getEtag()) {
                $response->addHeader('ETag', $etag);
            }

            return $response;
        });
    }

    /**
     * UID of a user whose mounts contain this fileid.
     *
     * Any mount serves identical bytes; the auth guard already ran,
     * so this reveals nothing about the file to the caller.
     */
    private function getFileOwner(int $fileid): string
    {
        $mountCache = \OC::$server->get(IUserMountCache::class);
        foreach ($mountCache->getMountsForFileId($fileid) as $info) {
            return $info->getUser()->getUID();
        }

        throw Exceptions::NotFoundFile($fileid);
    }

    /**
     * Only the configured Lens service account may call this endpoint.
     *
     * Runs before any file lookup, so failures never reveal file existence.
     */
    private function guardServiceAccount(): void
    {
        $serviceUser = SystemConfig::get('memories.lens.service_user');

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
}
