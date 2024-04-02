<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

const CLUSTER_DEG = 0.0003;

trait TimelineWriteMap
{
    protected IDBConnection $connection;

    /**
     * Get the cluster ID for a given point.
     * If the cluster ID changes, update the old cluster and the new cluster.
     *
     * @param int    $prevCluster The current cluster ID of the point
     * @param ?float $lat         The latitude of the point
     * @param ?float $lon         The longitude of the point
     * @param ?float $oldLat      The old latitude of the point
     * @param ?float $oldLon      The old longitude of the point
     *
     * @return int The new cluster ID
     */
    protected function mapGetCluster(int $prevCluster, ?float $lat, ?float $lon, ?float $oldLat, ?float $oldLon): int
    {
        // Just remove from old cluster if the point is no longer valid
        if (null === $lat || null === $lon) {
            $this->mapRemoveFromCluster($prevCluster, $oldLat, $oldLon);

            return -1;
        }

        // Get all possible clusters within CLUSTER_DEG radius
        $query = $this->connection->getQueryBuilder();
        $query->select('id', 'lat', 'lon')
            ->from('memories_mapclusters')
            ->andWhere($query->expr()->gte('lat', $query->createNamedParameter($lat - CLUSTER_DEG, IQueryBuilder::PARAM_STR)))
            ->andWhere($query->expr()->lte('lat', $query->createNamedParameter($lat + CLUSTER_DEG, IQueryBuilder::PARAM_STR)))
            ->andWhere($query->expr()->gte('lon', $query->createNamedParameter($lon - CLUSTER_DEG, IQueryBuilder::PARAM_STR)))
            ->andWhere($query->expr()->lte('lon', $query->createNamedParameter($lon + CLUSTER_DEG, IQueryBuilder::PARAM_STR)))
        ;
        $rows = Util::transaction(static fn () => $query->executeQuery()->fetchAll());

        // Find cluster closest to the point
        $minDist = PHP_INT_MAX;
        $minId = -1;
        foreach ($rows as &$r) {
            $clusterLat = (float) $r['lat'];
            $clusterLon = (float) $r['lon'];
            $dist = ($lat - $clusterLat) ** 2 + ($lon - $clusterLon) ** 2;
            if ($dist < $minDist) {
                $minDist = $dist;
                $minId = (int) $r['id'];
            }
        }

        // If no cluster found, create a new one
        if ($minId <= 0) {
            $this->mapRemoveFromCluster($prevCluster, $oldLat, $oldLon);

            return $this->mapCreateCluster($lat, $lon);
        }

        // If the file was previously in the same cluster, return that
        if ($prevCluster === $minId) {
            return $minId;
        }

        // If the file was previously in a different cluster,
        // remove it from the first cluster and add it to the second
        $this->mapRemoveFromCluster($prevCluster, $oldLat, $oldLon);
        $this->mapAddToCluster($minId, $lat, $lon);

        return $minId;
    }

    /**
     * Add a point to a cluster.
     *
     * @param int   $clusterId The ID of the cluster
     * @param float $lat       The latitude of the point
     * @param float $lon       The longitude of the point
     */
    protected function mapAddToCluster(int $clusterId, float $lat, float $lon): void
    {
        if ($clusterId <= 0) {
            return;
        }

        Util::transaction(function () use ($clusterId, $lat, $lon): void {
            $query = $this->connection->getQueryBuilder();
            $query->update('memories_mapclusters')
                ->set('point_count', $query->createFunction('point_count + 1'))
                ->set('lat_sum', $query->createFunction("lat_sum + {$lat}"))
                ->set('lon_sum', $query->createFunction("lon_sum + {$lon}"))
                ->where($query->expr()->eq('id', $query->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
                ->executeStatement()
            ;

            $this->mapUpdateAggregates($clusterId);
        });
    }

    /**
     * Create a new cluster.
     *
     * @param float $lat The latitude of the point
     * @param float $lon The longitude of the point
     *
     * @return int The ID of the new cluster
     */
    private function mapCreateCluster(float $lat, float $lon): int
    {
        return Util::transaction(function () use ($lat, $lon): int {
            $query = $this->connection->getQueryBuilder();
            $query->insert('memories_mapclusters')
                ->values([
                    'point_count' => $query->expr()->literal(1, IQueryBuilder::PARAM_INT),
                    'lat_sum' => $query->createNamedParameter($lat, IQueryBuilder::PARAM_STR),
                    'lon_sum' => $query->createNamedParameter($lon, IQueryBuilder::PARAM_STR),
                ])
            ;
            $query->executeStatement();

            $clusterId = $query->getLastInsertId();
            $this->mapUpdateAggregates($clusterId);

            return $clusterId;
        });
    }

    /**
     * Remove a point from a cluster.
     *
     * @param int    $clusterId The ID of the cluster
     * @param ?float $lat       The latitude of the point
     * @param ?float $lon       The longitude of the point
     */
    private function mapRemoveFromCluster(int $clusterId, ?float $lat, ?float $lon): void
    {
        if ($clusterId <= 0 || null === $lat || null === $lon) {
            return;
        }

        Util::transaction(function () use ($clusterId, $lat, $lon): void {
            $query = $this->connection->getQueryBuilder();
            $query->update('memories_mapclusters')
                ->set('point_count', $query->createFunction('point_count - 1'))
                ->set('lat_sum', $query->createFunction("lat_sum - {$lat}"))
                ->set('lon_sum', $query->createFunction("lon_sum - {$lon}"))
                ->where($query->expr()->eq('id', $query->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
            ;
            $query->executeStatement();

            $this->mapUpdateAggregates($clusterId);
        });
    }

    /**
     * Update the aggregate values of a cluster.
     *
     * @param int $clusterId The ID of the cluster
     */
    private function mapUpdateAggregates(int $clusterId): void
    {
        if ($clusterId <= 0) {
            return;
        }

        $query = $this->connection->getQueryBuilder();
        $query->update('memories_mapclusters')
            ->set('lat', $query->createFunction('lat_sum / point_count'))
            ->set('lon', $query->createFunction('lon_sum / point_count'))
            ->set('last_update', $query->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($query->expr()->eq('id', $query->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->gt('point_count', $query->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->executeStatement()
        ;
    }
}
