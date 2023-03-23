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

class PeopleFaceRecognitionController extends GenericClusterController
{
    use PeopleControllerUtils;

    protected function appName(): string
    {
        return 'Face Recognition';
    }

    protected function isEnabled(): bool
    {
        return $this->recognizeIsEnabled();
    }

    protected function getClusters(): array
    {
        return array_merge(
            $this->timelineQuery->getFaceRecognitionPersons($this->root, $this->model()),
            $this->timelineQuery->getFaceRecognitionClusters($this->root, $this->model())
        );
    }

    protected function getPhotos(string $name, ?int $limit = null): array
    {
        return $this->timelineQuery->getFaceRecognitionPhotos($name, $this->model(), $this->root, $limit) ?? [];
    }

    protected function sortPhotosForPreview(array &$photos)
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

    protected function getPreviewBlob($file, $photo): array
    {
        return $this->cropFace($file, $photo, 1.8);
    }

    private function model(): int
    {
        return (int) $this->config->getAppValue('facerecognition', 'model', -1);
    }
}
