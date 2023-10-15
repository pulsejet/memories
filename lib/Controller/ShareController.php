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

class ShareController extends GenericApiController
{
    /**
     * @NoAdminRequired
     *
     * Get the tokens of a node shared using an external link
     *
     * @param mixed $id
     * @param mixed $path
     */
    public function links($id, $path): Http\Response
    {
        return Util::guardEx(function () use ($id, $path) {
            $file = $this->getNodeByIdOrPath($id, $path);

            /** @var \OCP\Share\IManager $shareManager */
            $shareManager = \OC::$server->get(\OCP\Share\IManager::class);

            $shares = $shareManager->getSharesBy(Util::getUID(), \OCP\Share\IShare::TYPE_LINK, $file, true, 50, 0);
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
     *
     * @param mixed $id
     * @param mixed $path
     */
    public function createNode($id, $path): Http\Response
    {
        return Util::guardEx(function () use ($id, $path) {
            $file = $this->getNodeByIdOrPath($id, $path);

            $shareManager = \OC::$server->get(\OCP\Share\IManager::class);

            $share = $shareManager->newShare();
            $share->setNode($file);
            $share->setShareType(\OCP\Share\IShare::TYPE_LINK);
            $share->setSharedBy($this->userSession->getUser()->getUID());
            $share->setPermissions(\OCP\Constants::PERMISSION_READ);

            $share = $shareManager->createShare($share);

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

            /** @var \OCP\Share\IManager $shareManager */
            $shareManager = \OC::$server->get(\OCP\Share\IManager::class);

            $share = $shareManager->getShareById($id);

            if ($share->getSharedBy() !== $uid) {
                throw Exceptions::Forbidden('You are not the owner of this share');
            }

            $shareManager->deleteShare($share);

            return new JSONResponse([], Http::STATUS_OK);
        });
    }

    private function getNodeByIdOrPath($id, $path): \OCP\Files\Node
    {
        $uid = Util::getUID();

        try {
            $file = null;
            if ($id) {
                $file = $this->fs->getUserFile($id);
            } elseif ($path) {
                $file = Util::getUserFolder($uid)->get($path);
            }
        } catch (\OCP\Files\NotFoundException $e) {
            throw Exceptions::NotFoundFile($path ?? $id);
        }

        if (!$file || !$file->isShareable()) {
            throw Exceptions::Forbidden('File not sharable');
        }

        return $file;
    }

    private function makeShareResponse(\OCP\Share\IShare $share): array
    {
        /** @var \OCP\IURLGenerator $urlGenerator */
        $urlGenerator = \OC::$server->get(\OCP\IURLGenerator::class);

        $tok = $share->getToken();
        $exp = $share->getExpirationDate();
        $url = $urlGenerator->linkToRouteAbsolute('memories.Public.showShare', [
            'token' => $tok,
        ]);

        return [
            'id' => $share->getFullId(),
            'label' => $share->getLabel(),
            'token' => $tok,
            'url' => $url,
            'hasPassword' => $share->getPassword() ? true : false,
            'expiration' => $exp ? $exp->getTimestamp() : null,
            'editable' => $share->getPermissions() & \OCP\Constants::PERMISSION_UPDATE,
        ];
    }
}
