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

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IRequest;

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
     * Apply query transformations for days query.
     *
     * @param IQueryBuilder $query     Query builder
     * @param bool          $aggregate Whether this is an aggregate query
     */
    abstract public function transformDayQuery(&$query, bool $aggregate): void;

    /**
     * Apply post-query transformations for the given photo object.
     */
    public function transformDayPost(array &$row): void
    {
    }

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
     * Get a cluster backend.
     *
     * @param string $name Name of the backend
     *
     * @throws \Exception If the backend is not registered
     */
    public static function get(string $name): self
    {
        if (!\array_key_exists($name, self::$backends)) {
            throw new \Exception("Invalid clusters backend '{$name}'");
        }

        return \OC::$server->get(self::$backends[$name]);
    }

    /**
     * Apply all query transformations for the given request.
     */
    public static function getTransforms(IRequest $request): array
    {
        $transforms = [];
        foreach (array_keys(self::$backends) as $backendName) {
            if ($request->getParam($backendName)) {
                $backend = self::get($backendName);
                if ($backend->isEnabled()) {
                    $transforms[] = [$backend, 'transformDayQuery'];
                }
            }
        }

        return $transforms;
    }

    /**
     * Apply all post-query transformations for the given day object.
     */
    public static function applyDayPostTransforms(IRequest $request, array &$row): void
    {
        foreach (array_keys(self::$backends) as $backendName) {
            if ($request->getParam($backendName)) {
                $backend = self::get($backendName);
                if ($backend->isEnabled()) {
                    $backend->transformDayPost($row);
                }
            }
        }
    }

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
     * @return array [Blob, mimetype] of data
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
