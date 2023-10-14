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

abstract class Backend
{
    /**
     * A human-readable name for the app.
     * Used for error messages.
     */
    abstract public static function appName(): string;

    /**
     * Get name of the cluster type.
     */
    abstract public static function clusterType(): string;

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
    public function transformDayPost(array &$row): void {}

    /**
     * Get the cluster list for the current user.
     *
     * If the signature of this function changes, the
     * getClusters function must be updated to match.
     *
     * @param int $fileid Filter clusters by file ID (optional)
     */
    abstract public function getClustersInternal(int $fileid = 0): array;

    /**
     * Get a cluster ID for the given cluster.
     */
    abstract public static function getClusterId(array $cluster);

    /**
     * Get a list of photos with any extra parameters for the given cluster
     * Used for preview generation and download.
     *
     * @param string $name  Identifier for the cluster
     * @param int    $limit Maximum number of photos to return
     */
    abstract public function getPhotos(string $name, ?int $limit = null): array;

    /**
     * Human readable name for the cluster.
     */
    public function clusterName(string $name): string
    {
        return $name;
    }

    /**
     * Put the photo objects in priority list.
     * Works on the array in place.
     */
    public function sortPhotosForPreview(array &$photos): void
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

    /**
     * Calls the getClusters implementation and appends the
     * result with the cluster_id and cluster_type values.
     *
     * @param int $fileid Filter clusters by file ID (optional)
     */
    final public function getClusters(int $fileid): array
    {
        $list = $this->getClustersInternal($fileid);

        foreach ($list as &$cluster) {
            $cluster['cluster_id'] = $this->getClusterId($cluster);
            $cluster['cluster_type'] = $this->clusterType();
        }

        return $list;
    }

    /**
     * Register the backend. Do not override.
     */
    final public static function register(): void
    {
        Manager::register(static::clusterType(), static::class);
    }
}
