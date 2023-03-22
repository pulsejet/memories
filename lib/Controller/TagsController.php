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

use OCA\Memories\Errors;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

class TagsController extends GenericClusterController
{
    /**
     * @NoAdminRequired
     *
     * Set tags for a file
     */
    public function set(int $id, array $add, array $remove): Http\Response
    {
        // Check tags enabled for this user
        if (!$this->tagsIsEnabled()) {
            return Errors::NotEnabled($this->appName());
        }

        // Check the user is allowed to edit the file
        $file = $this->getUserFile($id);
        if (null === $file) {
            return Errors::NotFoundFile($id);
        }

        // Check the user is allowed to edit the file
        if (!$file->isUpdateable() || !($file->getPermissions() & \OCP\Constants::PERMISSION_UPDATE)) {
            return Errors::ForbiddenFileUpdate($file->getName());
        }

        // Get mapper from tags to objects
        $om = \OC::$server->get(\OCP\SystemTag\ISystemTagObjectMapper::class);

        // Add and remove tags
        $om->assignTags((string) $id, 'files', $add);
        $om->unassignTags((string) $id, 'files', $remove);

        return new JSONResponse([], Http::STATUS_OK);
    }

    protected function appName(): string
    {
        return 'Tags';
    }

    protected function isEnabled(): bool
    {
        return $this->tagsIsEnabled();
    }

    protected function getClusters(): array
    {
        return $this->timelineQuery->getTags($this->root);
    }

    protected function getFileIds(string $name, ?int $limit = null): array
    {
        $list = $this->timelineQuery->getTagFiles($name, $this->root, $limit) ?? [];

        return array_map(fn ($item) => (int) $item['fileid'], $list);
    }
}
