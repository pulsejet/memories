<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

trait TimelineQueryMap
{
    protected IDBConnection $connection;

    public function transformMapBoundsFilter(IQueryBuilder &$query, string $userId, string $bounds, $table = 'm')
    {
        $bounds = explode(',', $bounds);
        $bounds = array_map('floatval', $bounds);
        if (4 !== \count($bounds)) {
            return;
        }

        $latCol = $table.'.lat';
        $lonCol = $table.'.lon';
        $query->andWhere(
            $query->expr()->andX(
                $query->expr()->gte($latCol, $query->createNamedParameter($bounds[0], IQueryBuilder::PARAM_STR)),
                $query->expr()->lte($latCol, $query->createNamedParameter($bounds[1], IQueryBuilder::PARAM_STR)),
                $query->expr()->gte($lonCol, $query->createNamedParameter($bounds[2], IQueryBuilder::PARAM_STR)),
                $query->expr()->lte($lonCol, $query->createNamedParameter($bounds[3], IQueryBuilder::PARAM_STR))
            )
        );
    }

    public function getMapClusters(
        float $gridLen,
        string $bounds,
        TimelineRoot &$root
    ): array {
        $query = $this->connection->getQueryBuilder();

        // Get the average location of each cluster
        $lat = $query->createFunction('AVG(c.lat) AS lat');
        $lon = $query->createFunction('AVG(c.lon) AS lon');
        $count = $query->createFunction('COUNT(m.fileid) AS count');

        $query->select($lat, $lon, $count)
            ->from('memories_map_clusters', 'c')
        ;

        if ($gridLen > 0.02) {
            // Coarse grouping
            $query->addSelect($query->createFunction('MAX(c.id) as id'));
            $query->addGroupBy($query->createFunction("CAST(c.lat / {$gridLen} AS INT)"));
            $query->addGroupBy($query->createFunction("CAST(c.lon / {$gridLen} AS INT)"));
        } else {
            // Fine grouping
            $query->addSelect('c.id')->groupBy('c.id');
        }

        // JOIN with memories for files from the current user
        $query->innerJoin('c', 'memories', 'm', $query->expr()->eq('c.id', 'm.map_cluster_id'));

        // JOIN with filecache for existing files
        $query = $this->joinFilecache($query, $root, true, false);

        // Bound the query to the map bounds
        $this->transformMapBoundsFilter($query, '', $bounds, 'c');

        // Execute query
        $cursor = $this->executeQueryWithCTEs($query);
        $res = $cursor->fetchAll();
        $cursor->closeCursor();

        // Post-process results
        $clusters = [];
        foreach ($res as &$cluster) {
            $c = [
                'center' => [
                    (float) $cluster['lat'],
                    (float) $cluster['lon'],
                ],
                'count' => (float) $cluster['count'],
            ];
            if (\array_key_exists('id', $cluster)) {
                $c['id'] = (int) $cluster['id'];
            }
            $clusters[] = $c;
        }

        return $clusters;
    }

    public function getMapClusterPreviews(int $clusterId, TimelineRoot &$root)
    {
        $query = $this->connection->getQueryBuilder();

        // SELECT all photos with this tag
        $query->select('f.fileid', 'f.etag')->from('memories', 'm')->where(
            $query->expr()->eq('m.map_cluster_id', $query->createNamedParameter($clusterId, IQueryBuilder::PARAM_INT))
        );

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->joinFilecache($query, $root, true, false);

        // MAX 8
        $query->setMaxResults(8);

        // FETCH tag previews
        $cursor = $this->executeQueryWithCTEs($query);
        $ans = $cursor->fetchAll();

        // Post-process
        foreach ($ans as &$row) {
            $row['fileid'] = (int) $row['fileid'];
        }

        return $ans;
    }
}
