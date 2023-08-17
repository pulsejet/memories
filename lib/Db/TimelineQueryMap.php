<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

trait TimelineQueryMap
{
    use TimelineQueryDays;

    protected IDBConnection $connection;

    public function transformMapBoundsFilter(IQueryBuilder &$query, bool $aggregate, string $bounds, string $table = 'm')
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
        string $bounds
    ): array {
        $query = $this->connection->getQueryBuilder();

        // Get the average location of each cluster
        $query->selectAlias($query->createFunction('MAX(c.id)'), 'id')
            ->selectAlias($query->createFunction('COUNT(m.fileid)'), 'count')
            ->selectAlias($query->createFunction('AVG(c.lat)'), 'lat')
            ->selectAlias($query->createFunction('AVG(c.lon)'), 'lon')
            ->from('memories_mapclusters', 'c')
        ;

        // Coarse grouping
        $gridParam = $query->createNamedParameter($gridLen, IQueryBuilder::PARAM_STR);
        $query->addGroupBy($query->createFunction("FLOOR(c.lat / {$gridParam})"))
            ->addGroupBy($query->createFunction("FLOOR(c.lon / {$gridParam})"))
        ;

        // JOIN with memories for files from the current user
        $query->innerJoin('c', 'memories', 'm', $query->expr()->eq('c.id', 'm.mapcluster'));

        // JOIN with filecache for existing files
        $query = $this->joinFilecache($query);

        // Bound the query to the map bounds
        $this->transformMapBoundsFilter($query, false, $bounds, 'c');

        // Execute query
        $res = $this->executeQueryWithCTEs($query)->fetchAll();

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

    public function getMapClusterPreviews(array $clusterIds)
    {
        $query = $this->connection->getQueryBuilder();

        // SELECT all photos with this tag
        $query->selectAlias($query->createFunction('MAX(m.fileid)'), 'fileid')
            ->from('memories', 'm')
            ->where($query->expr()->in('m.mapcluster', $query->createNamedParameter(
                $clusterIds,
                IQueryBuilder::PARAM_INT_ARRAY
            )))
        ;

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->joinFilecache($query);

        // GROUP BY the cluster
        $query->groupBy('m.mapcluster');

        // Get the fileIds
        $cursor = $this->executeQueryWithCTEs($query);
        $fileIds = $cursor->fetchAll(\PDO::FETCH_COLUMN);

        // SELECT these files from the filecache
        $query = $this->connection->getQueryBuilder();
        $query->select('m.fileid', 'm.dayid', 'm.mapcluster', 'm.h', 'm.w', 'f.etag')
            ->from('memories', 'm')
            ->innerJoin('m', 'filecache', 'f', $query->expr()->eq('m.fileid', 'f.fileid'))
            ->where($query->expr()->in('m.fileid', $query->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
        ;
        $files = $query->executeQuery()->fetchAll();

        // Post-process
        foreach ($files as &$row) {
            $row['fileid'] = (int) $row['fileid'];
            $row['mapcluster'] = (int) $row['mapcluster'];
            $row['dayid'] = (int) $row['dayid'];
            $row['h'] = (int) $row['h'];
            $row['w'] = (int) $row['w'];
        }

        return $files;
    }

    /**
     * Gets the suggested initial coordinates for the map.
     * Uses the coordinates of the newest photo (by date).
     */
    public function getMapInitialPosition(): ?array
    {
        $query = $this->connection->getQueryBuilder();

        // SELECT coordinates
        $query->select('m.lat', 'm.lon')->from('memories', 'm');

        // WHERE this photo is in the user's requested folder recursively
        $query = $this->joinFilecache($query);

        // WHERE this photo has coordinates
        $query->where($query->expr()->andX(
            $query->expr()->isNotNull('m.lat'),
            $query->expr()->isNotNull('m.lon')
        ));

        // ORDER BY datetaken DESC
        $query->orderBy('m.datetaken', 'DESC');

        // LIMIT 1
        $query->setMaxResults(1);

        // FETCH coordinates
        $coords = $this->executeQueryWithCTEs($query)->fetch();

        return $coords ?: null;
    }
}
