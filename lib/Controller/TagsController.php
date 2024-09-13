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
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

class TagsController extends GenericApiController
{
    /**
     * @NoAdminRequired
     *
     * @param int   $id     File ID
     * @param int[] $add    Tags to add
     * @param int[] $remove Tags to remove
     *
     * Set tags for a file
     */
    public function set(int $id, ?array $add, ?array $remove): Http\Response
    {
        return Util::guardEx(function () use ($id, $add, $remove) {
            // Check tags enabled for this user
            if (!Util::tagsIsEnabled()) {
                throw Exceptions::NotEnabled('Tags');
            }

            // Check the user is allowed to edit the file
            $file = $this->fs->getUserFile($id);

            // Check the user is allowed to edit the file
            if (!$file->isUpdateable()) {
                throw Exceptions::ForbiddenFileUpdate($file->getName());
            }

            // Get mapper from tags to objects
            $om = \OC::$server->get(\OCP\SystemTag\ISystemTagObjectMapper::class);

            // Add tags
            if (null !== $add && \count($add) > 0) {
                $om->assignTags((string) $id, 'files', $add);
            }

            // Remove tags
            if (null !== $remove && \count($remove) > 0) {
                $om->unassignTags((string) $id, 'files', $remove);
            }

            return new JSONResponse([], Http::STATUS_OK);
        });
    }
}
