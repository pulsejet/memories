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

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Db\FsManager;
use OCA\Memories\Exceptions;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\SystemTag\ISystemTagObjectMapper;

final class TagsController extends ApiController
{
    public function __construct(
        IRequest $request,
        protected FsManager $fs,
        protected ISystemTagObjectMapper $tagObjectMapper,
        protected SystemConfig $systemConfig,
        protected Util $util,
    ) {
        parent::__construct(Application::APPNAME, $request);
    }

    /**
     * @param int   $id     File ID
     * @param int[] $add    Tags to add
     * @param int[] $remove Tags to remove
     *
     * Set tags for a file
     */
    #[NoAdminRequired]
    public function set(int $id, ?array $add, ?array $remove): Http\Response
    {
        return $this->util->guardEx(function () use ($id, $add, $remove) {
            // Check tags enabled for this user
            if (!$this->systemConfig->tagsIsEnabled()) {
                throw Exceptions::NotEnabled('Tags');
            }

            // Check the user is allowed to edit the file
            $file = $this->fs->getUserFile($id);

            // Check the user is allowed to edit the file
            if (!$file->isUpdateable()) {
                throw Exceptions::ForbiddenFileUpdate($file->getName());
            }

            // Add tags
            if (null !== $add && \count($add) > 0) {
                $this->tagObjectMapper->assignTags((string) $id, 'files', array_values(array_map(static fn ($t): string => (string) $t, $add)));
            }

            // Remove tags
            if (null !== $remove && \count($remove) > 0) {
                $this->tagObjectMapper->unassignTags((string) $id, 'files', array_values(array_map(static fn ($t): string => (string) $t, $remove)));
            }

            return new JSONResponse([], Http::STATUS_OK);
        });
    }
}
