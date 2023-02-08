<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

trait TimelineQueryMap
{
    public function transformMapBoundsFilter(IQueryBuilder &$query, string $userId, string $bounds)
    {
        $bounds = explode(',', $bounds);
        $bounds = array_map('floatval', $bounds);
        if (4 !== \count($bounds)) {
            return;
        }

        $query->andWhere(
            $query->expr()->andX(
                $query->expr()->gte('m.lat', $query->createNamedParameter($bounds[0], IQueryBuilder::PARAM_STR)),
                $query->expr()->lte('m.lat', $query->createNamedParameter($bounds[1], IQueryBuilder::PARAM_STR)),
                $query->expr()->gte('m.lon', $query->createNamedParameter($bounds[2], IQueryBuilder::PARAM_STR)),
                $query->expr()->lte('m.lon', $query->createNamedParameter($bounds[3], IQueryBuilder::PARAM_STR))
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
        $avgLat = $query->createFunction('AVG(m.lat) AS avgLat');
        $avgLng = $query->createFunction('AVG(m.lon) AS avgLon');
        $count = $query->createFunction('COUNT(m.fileid) AS count');
        $query->select($avgLat, $avgLng, $count)
            ->from('memories', 'm')
        ;

        // JOIN with filecache for existing files
        $query = $this->joinFilecache($query, $root, true, false);

        // Group by cluster
        $query->addGroupBy($query->createFunction("m.lat DIV {$gridLen}"));
        $query->addGroupBy($query->createFunction("m.lon DIV {$gridLen}"));

        // Apply all transformations (including map bounds)
        $this->transformMapBoundsFilter($query, '', $bounds);

        // Execute query
        $cursor = $this->executeQueryWithCTEs($query);
        $res = $cursor->fetchAll();
        $cursor->closeCursor();

        // Post-process results
        $clusters = [];
        foreach ($res as $cluster) {
            $clusters[] =
            [
                'center' => [
                    (float) $cluster['avgLat'],
                    (float) $cluster['avgLon'],
                ],
                'count' => (float) $cluster['count'],
            ];
        }

        return $clusters;
    }
}
