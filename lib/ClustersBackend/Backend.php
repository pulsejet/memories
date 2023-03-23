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

abstract class Backend
{
    /** Mapping of backend name to className */
    public static array $backends = [];

    /**
     * A human-readable name for the app.
     * Used for error messages.
     */
    abstract public function appName(): string;

    /**
     * Whether the app is enabled for the current user.
     */
    abstract public function isEnabled(): bool;

    /**
     * Get the cluster list for the current user.
     */
    abstract public function getClusters(): array;

    /**
     * Get a list of photos with any extra parameters for the given cluster
     * Used for preview generation and download.
     *
     * @param string $name  Identifier for the cluster
     * @param int    $limit Maximum number of photos to return
     */
    abstract public function getPhotos(string $name, ?int $limit = null): array;

    /**
     * Register a new backend.
     *
     * @param mixed $name
     * @param mixed $className
     */
    public static function register($name, $className): void
    {
        self::$backends[$name] = $className;
    }

    /**
     * Human readable name for the cluster.
     */
    public function clusterName(string $name)
    {
        return $name;
    }

    /**
     * Put the photo objects in priority list.
     * Works on the array in place.
     */
    public function sortPhotosForPreview(array &$photos)
    {
        shuffle($photos);
    }

    /**
     * Quality to use for the preview file.
     */
    public function getPreviewQuality(): int
    {
        return 512;
    }

    /**
     * Perform any post processing and get the blob from the preview file.
     *
     * @param \OCP\Files\SimpleFS\ISimpleFile $file  Preview file
     * @param array                           $photo Photo object
     *
     * @return [Blob, mimetype] of data
     */
    public function getPreviewBlob($file, $photo): array
    {
        return [$file->getContent(), $file->getMimeType()];
    }

    /**
     * Get the file ID for a photo object.
     */
    public function getFileId(array $photo): int
    {
        return (int) $photo['fileid'];
    }
}
