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
use OCP\IConfig;
use OCP\IUserSession;

class FaceRecognitionBackend extends Backend
{
    use PeopleBackendUtils;

    public TimelineRoot $root;
    protected TimelineQuery $timelineQuery;
    protected string $userId;
    protected IAppManager $appManager;
    protected IConfig $config;

    public function __construct(
        TimelineQuery $timelineQuery,
        IUserSession $userSession,
        IAppManager $appManager,
        IConfig $config
    ) {
        $this->timelineQuery = $timelineQuery;
        $this->userId = $userSession->getUser()->getUID();
        $this->appManager = $appManager;
        $this->config = $config;
    }

    public function appName(): string
    {
        return 'Face Recognition';
    }

    public function isEnabled(): bool
    {
        return \OCA\Memories\Util::facerecognitionIsInstalled($this->appManager)
               && \OCA\Memories\Util::facerecognitionIsEnabled($this->config, $this->userId);
    }

    public function getClusters(): array
    {
        return array_merge(
            $this->timelineQuery->getFaceRecognitionPersons($this->root, $this->model()),
            $this->timelineQuery->getFaceRecognitionClusters($this->root, $this->model())
        );
    }

    public function getPhotos(string $name, ?int $limit = null): array
    {
        return $this->timelineQuery->getFaceRecognitionPhotos($name, $this->model(), $this->root, $limit) ?? [];
    }

    public function sortPhotosForPreview(array &$photos)
    {
        // Convert to recognize format (percentage position-size)
        foreach ($photos as &$p) {
            $p['x'] = (float) $p['x'] / (float) $p['image_width'];
            $p['y'] = (float) $p['y'] / (float) $p['image_height'];
            $p['width'] = (float) $p['width'] / (float) $p['image_width'];
            $p['height'] = (float) $p['height'] / (float) $p['image_height'];
        }

        $this->sortByScores($photos);
    }

    public function getPreviewBlob($file, $photo): array
    {
        return $this->cropFace($file, $photo, 1.8);
    }

    public function getPreviewQuality(): int
    {
        return 2048;
    }

    private function model(): int
    {
        return (int) $this->config->getAppValue('facerecognition', 'model', -1);
    }
}
