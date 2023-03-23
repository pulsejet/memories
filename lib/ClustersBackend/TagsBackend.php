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

namespace OCA\Memories\ClustersBackend;

use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Db\TimelineRoot;
use OCP\App\IAppManager;
use OCP\IUserSession;

class TagsBackend extends Backend
{
    public TimelineRoot $root;
    protected TimelineQuery $timelineQuery;
    protected string $userId;
    protected IAppManager $appManager;

    public function __construct(
        TimelineQuery $timelineQuery,
        IUserSession $userSession,
        IAppManager $appManager
    ) {
        $this->timelineQuery = $timelineQuery;
        $this->userId = $userSession->getUser()->getUID();
        $this->appManager = $appManager;
    }

    public function appName(): string
    {
        return 'Tags';
    }

    public function isEnabled(): bool
    {
        return \OCA\Memories\Util::tagsIsEnabled($this->appManager);
    }

    public function getClusters(): array
    {
        return $this->timelineQuery->getTags($this->root);
    }

    public function getPhotos(string $name, ?int $limit = null): array
    {
        return $this->timelineQuery->getTagPhotos($name, $this->root, $limit) ?? [];
    }
}
