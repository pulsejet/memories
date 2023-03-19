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

use OCA\Memories\Errors;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

class ShareController extends ApiBase
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
        $file = $this->getNodeByIdOrPath($id, $path);
        if (!$file) {
            return Errors::Forbidden('file');
        }

        /** @var \OCP\Share\IManager $shareManager */
        $shareManager = \OC::$server->get(\OCP\Share\IManager::class);

        $shares = $shareManager->getSharesBy($this->getUID(), \OCP\Share\IShare::TYPE_LINK, $file, true, 50, 0);
        if (empty($shares)) {
            return Errors::NotFound('external links');
        }

        $links = array_map([$this, 'makeShareResponse'], $shares);

        return new JSONResponse($links, Http::STATUS_OK);
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
        $file = $this->getNodeByIdOrPath($id, $path);
        if (!$file) {
            return Errors::Forbidden('You are not allowed to share this file');
        }

        /** @var \OCP\Share\IManager $shareManager */
        $shareManager = \OC::$server->get(\OCP\Share\IManager::class);

        /** @var \OCP\Share\IShare $share */
        $share = $shareManager->newShare();
        $share->setNode($file);
        $share->setShareType(\OCP\Share\IShare::TYPE_LINK);
        $share->setSharedBy($this->userSession->getUser()->getUID());
        $share->setPermissions(\OCP\Constants::PERMISSION_READ);

        $share = $shareManager->createShare($share);

        return new JSONResponse($this->makeShareResponse($share), Http::STATUS_OK);
    }

    /**
     * @NoAdminRequired
     *
     * Delete an external link share
     */
    public function deleteShare(string $id): Http\Response
    {
        $uid = $this->getUID();
        if (!$uid) {
            return Errors::NotLoggedIn();
        }

        /** @var \OCP\Share\IManager $shareManager */
        $shareManager = \OC::$server->get(\OCP\Share\IManager::class);

        $share = $shareManager->getShareById($id);

        if ($share->getSharedBy() !== $uid) {
            return Errors::Forbidden('You are not the owner of this share');
        }

        $shareManager->deleteShare($share);

        return new JSONResponse([], Http::STATUS_OK);
    }

    private function getNodeByIdOrPath($id, $path)
    {
        $uid = $this->getUID();
        if (!$uid) {
            return null;
        }

        $file = null;
        if ($id) {
            $file = $this->getUserFile($id);
        } elseif ($path) {
            try {
                $userFolder = $this->rootFolder->getUserFolder($uid);
                $file = $userFolder->get($path);
            } catch (\OCP\Files\NotFoundException $e) {
                return null;
            }
        }

        if (!$file || !$file->isShareable()) {
            return null;
        }

        return $file;
    }

    private function makeShareResponse(\OCP\Share\IShare $share)
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
