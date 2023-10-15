<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2023 Varun Patil <radialapps@gmail.com>
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
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Share\IManager;
use OCP\Share\IShare;

class ShareController extends GenericApiController
{
    /**
     * @NoAdminRequired
     *
     * Get the tokens of a node shared using an external link
     */
    public function links(?int $id, ?string $path): Http\Response
    {
        return Util::guardEx(function () use ($id, $path) {
            $file = $this->getNodeByIdOrPath($id, $path);

            $shares = \OC::$server->get(IManager::class)
                ->getSharesBy(Util::getUID(), IShare::TYPE_LINK, $file, true, 50, 0)
            ;

            if (empty($shares)) {
                throw Exceptions::NotFound('external links');
            }

            $links = array_map(fn ($s) => $this->makeShareResponse($s), $shares);

            return new JSONResponse($links, Http::STATUS_OK);
        });
    }

    /**
     * @NoAdminRequired
     *
     * Share a node using an external link
     */
    public function createNode(?int $id, ?string $path): Http\Response
    {
        return Util::guardEx(function () use ($id, $path) {
            $file = $this->getNodeByIdOrPath($id, $path);

            $manager = \OC::$server->get(IManager::class);

            $share = $manager->createShare(
                $manager->newShare()
                    ->setNode($file)
                    ->setShareType(\OCP\Share\IShare::TYPE_LINK)
                    ->setSharedBy(Util::getUID())
                    ->setPermissions(\OCP\Constants::PERMISSION_READ),
            );

            return new JSONResponse($this->makeShareResponse($share), Http::STATUS_OK);
        });
    }

    /**
     * @NoAdminRequired
     *
     * Delete an external link share
     */
    public function deleteShare(string $id): Http\Response
    {
        return Util::guardEx(static function () use ($id) {
            $uid = Util::getUID();

            $manager = \OC::$server->get(\OCP\Share\IManager::class);

            $share = $manager->getShareById($id);

            if ($share->getSharedBy() !== $uid) {
                throw Exceptions::Forbidden('You are not the owner of this share');
            }

            $manager->deleteShare($share);

            return new JSONResponse([], Http::STATUS_OK);
        });
    }

    private function getNodeByIdOrPath(?int $id, ?string $path): \OCP\Files\Node
    {
        $uid = Util::getUID();

        try {
            $file = null;
            if ($id) {
                $file = $this->fs->getUserFile($id);
            } elseif ($path) {
                $file = Util::getUserFolder($uid)->get($path);
            }
        } catch (\OCP\Files\NotFoundException) {
            throw Exceptions::NotFoundFile($path ?? $id);
        }

        if (!$file || !$file->isShareable()) {
            throw Exceptions::Forbidden('File not sharable');
        }

        return $file;
    }

    private function makeShareResponse(IShare $share): array
    {
        $token = $share->getToken();
        $url = \OC::$server->get(\OCP\IURLGenerator::class)
            ->linkToRouteAbsolute('memories.Public.showShare', ['token' => $token])
        ;

        /**
         * @psalm-suppress RedundantConditionGivenDocblockType
         * @psalm-suppress DocblockTypeContradiction
         */
        $expiration = $share->getExpirationDate()?->getTimestamp();

        return [
            'id' => $share->getFullId(),
            'label' => $share->getLabel(),
            'token' => $token,
            'url' => $url,
            'hasPassword' => $share->getPassword() ? true : false,
            'expiration' => $expiration,
            'editable' => $share->getPermissions() & \OCP\Constants::PERMISSION_UPDATE,
        ];
    }
}
