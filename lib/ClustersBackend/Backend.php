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

use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\SimpleFS\ISimpleFile;

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
    abstract public function transformDayQuery(IQueryBuilder &$query, bool $aggregate): void;

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
    abstract public static function getClusterId(array $cluster): int|string;

    /**
     * Get a list of photos with any extra parameters for the given cluster
     * Used for preview generation and download.
     *
     * @param string $name   Identifier for the cluster
     * @param int    $limit  Maximum number of photos to return (optional)
     * @param int    $fileid Filter photos by file ID (optional)
     *
     * Setting $limit to -6 will attempt to fetch the cover photo for the cluster
     * This will be returned as an array with a single element if found
     */
    abstract public function getPhotos(
        string $name,
        ?int $limit = null,
        ?int $fileid = null,
    ): array;

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
     * @param ISimpleFile $file  Preview file
     * @param array       $photo Photo object
     *
     * @return array [Blob, mimetype] of data
     */
    public function getPreviewBlob(ISimpleFile $file, array $photo): array
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
     * Get the cover object ID for a photo object.
     */
    public function getCoverObjId(array $photo): int
    {
        return $this->getFileId($photo);
    }

    /**
     * Get the cluster ID for a photo object.
     */
    public function getClusterIdFrom(array $photo): int
    {
        throw new \Exception('getClusterIdFrom not implemented by '.$this::class);
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

    /**
     * Set the cover photo for the given cluster.
     *
     * @param array $photo  Photo object
     * @param bool  $manual Whether this is a manual selection
     */
    final public function setCover(array $photo, bool $manual = false): void
    {
        try {
            Util::transaction(function () use ($photo, $manual): void {
                $connection = \OC::$server->get(\OCP\IDBConnection::class);
                $query = $connection->getQueryBuilder();
                $query->delete('memories_covers')
                    ->where($query->expr()->eq('uid', $query->createNamedParameter(Util::getUser()->getUID())))
                    ->andWhere($query->expr()->eq('clustertype', $query->createNamedParameter($this->clusterType())))
                    ->andWhere($query->expr()->eq('clusterid', $query->createNamedParameter($this->getClusterIdFrom($photo))))
                    ->executeStatement()
                ;

                $query = $connection->getQueryBuilder();
                $query->insert('memories_covers')
                    ->values([
                        'uid' => $query->createNamedParameter(Util::getUser()->getUID()),
                        'clustertype' => $query->createNamedParameter($this->clusterType()),
                        'clusterid' => $query->createNamedParameter($this->getClusterIdFrom($photo)),
                        'objectid' => $query->createNamedParameter($this->getCoverObjId($photo)),
                        'fileid' => $query->createNamedParameter($this->getFileId($photo)),
                        'auto' => $query->createNamedParameter($manual ? 0 : 1, \PDO::PARAM_INT),
                        'timestamp' => $query->createNamedParameter(time(), \PDO::PARAM_INT),
                    ])
                    ->executeStatement()
                ;
            });
        } catch (\Exception $e) {
            if ($manual) {
                throw $e;
            }

            \OC::$server->get(\Psr\Log\LoggerInterface::class)
                ->error('Failed to set cover', ['app' => 'memories', 'exception' => $e->getMessage()])
            ;
        }
    }

    /**
     * Join the list query to get covers.
     *
     * @param IQueryBuilder $query                Query builder
     * @param string        $clusterTable         Alias name for the cluster list
     * @param string        $clusterTableId       Column name for the cluster ID in clusterTable
     * @param string        $objectTable          Table name for the object mapping
     * @param string        $objectTableObjectId  Column name for the object ID in objectTable
     * @param string        $objectTableClusterId Column name for the cluster ID in objectTable
     * @param bool          $validateCluster      Whether to validate the cluster
     * @param bool          $validateFilecache    Whether to validate the filecache
     * @param mixed         $user                 Query expression for user ID to use for the covers
     */
    final protected function joinCovers(
        IQueryBuilder &$query,
        string $clusterTable,
        string $clusterTableId,
        string $objectTable,
        string $objectTableObjectId,
        string $objectTableClusterId,
        bool $validateCluster = true,
        bool $validateFilecache = true,
        string $field = 'cover',
        mixed $user = null,
    ): void {
        // Create aliases for the tables
        $mcov = "m_cov_{$field}";
        $mcov_f = "{$mcov}_f";

        // Default to current user
        $user = $user ?? $query->expr()->literal(Util::getUser()->getUID());

        // Clauses for the JOIN
        $joinClauses = [
            $query->expr()->eq("{$mcov}.uid", $user),
            $query->expr()->eq("{$mcov}.clustertype", $query->expr()->literal($this->clusterType())),
            $query->expr()->eq("{$mcov}.clusterid", "{$clusterTable}.{$clusterTableId}"),
        ];

        // Subquery if the preview is still valid for this cluster
        if ($validateCluster) {
            $validSq = $query->getConnection()->getQueryBuilder();
            $validSq->select($validSq->expr()->literal(1))
                ->from($objectTable, 'cov_objs')
                ->where($validSq->expr()->eq($query->expr()->castColumn("cov_objs.{$objectTableObjectId}", IQueryBuilder::PARAM_INT), "{$mcov}.objectid"))
                ->andWhere($validSq->expr()->eq("cov_objs.{$objectTableClusterId}", "{$clusterTable}.{$clusterTableId}"))
            ;

            $joinClauses[] = $query->createFunction("EXISTS ({$validSq->getSQL()})");
        }

        // Subquery if the file is still in the user's timeline tree
        if ($validateFilecache) {
            $treeSq = $query->getConnection()->getQueryBuilder();
            $treeSq->select($treeSq->expr()->literal(1))
                ->from('filecache', 'cov_f')
                ->innerJoin('cov_f', 'cte_folders', 'cov_cte_f', $treeSq->expr()->andX(
                    $treeSq->expr()->eq('cov_cte_f.fileid', 'cov_f.parent'),
                    $treeSq->expr()->eq('cov_cte_f.hidden', $treeSq->expr()->literal(0, \PDO::PARAM_INT)),
                ))
                ->where($treeSq->expr()->eq('cov_f.fileid', "{$mcov}.fileid"))
            ;

            $joinClauses[] = $query->createFunction("EXISTS ({$treeSq->getSQL()})");
        }

        // LEFT JOIN to get all the covers that we can
        $query->leftJoin($clusterTable, 'memories_covers', $mcov, $query->expr()->andX(...$joinClauses));

        // JOIN with filecache to get the etag
        $query->leftJoin($mcov, 'filecache', $mcov_f, $query->expr()->eq("{$mcov_f}.fileid", "{$mcov}.fileid"));

        // SELECT the cover
        $query->selectAlias($query->createFunction("MAX({$mcov}.objectid)"), $field);
        $query->selectAlias($query->createFunction("MAX({$mcov_f}.etag)"), "{$field}_etag");
    }

    /**
     * Filter the photos query to get only the cover for this user.
     *
     * @param IQueryBuilder $query                Query builder
     * @param string        $objectTable          Table name for the object mapping
     * @param string        $objectTableObjectId  Column name for the object ID in objectTable
     * @param string        $objectTableClusterId Column name for the cluster ID in objectTable
     */
    final protected function filterCover(
        IQueryBuilder &$query,
        string $objectTable,
        string $objectTableObjectId,
        string $objectTableClusterId,
    ): void {
        $query->innerJoin($objectTable, 'memories_covers', 'm_cov', $query->expr()->andX(
            $query->expr()->eq('m_cov.uid', $query->expr()->literal(Util::getUser()->getUID())),
            $query->expr()->eq('m_cov.clustertype', $query->expr()->literal($this->clusterType())),
            $query->expr()->eq('m_cov.clusterid', "{$objectTable}.{$objectTableClusterId}"),
            $query->expr()->eq('m_cov.objectid', $query->expr()->castColumn("{$objectTable}.{$objectTableObjectId}", IQueryBuilder::PARAM_INT)),
        ));
    }
}
