<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

trait TimelineQueryMap
{
    use TimelineQueryBase;
    use TimelineQueryDays;

    public function transformMapBoundsFilter(IQueryBuilder &$query, bool $aggregate, string $bounds, string $table = 'm'): void
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
                $query->expr()->lte($lonCol, $query->createNamedParameter($bounds[3], IQueryBuilder::PARAM_STR)),
            ),
        );
    }

    public function getMapClusters(float $gridLen, string $bounds): array
    {
        $query = $this->connection->getQueryBuilder();

        // Get the average location of each cluster
        $query->selectAlias($query->func()->max('c.id'), 'id')
            ->selectAlias($query->func()->count('m.fileid'), 'count')
            ->selectAlias($query->func()->max('m.fileid'), 'preview_fileid')
            ->selectAlias(SQL::average($query, 'c.lat'), 'lat')
            ->selectAlias(SQL::average($query, 'c.lon'), 'lon')
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
        $query = $this->filterFilecache($query);

        // Bound the query to the map bounds
        $this->transformMapBoundsFilter($query, false, $bounds, 'c');

        // Execute query
        $res = $this->executeQueryWithCTEs($query)->fetchAllAssociative();

        // Post-process results
        return array_map(static fn ($row) => [
            'id' => (int) $row['id'],
            'center' => [
                (float) $row['lat'],
                (float) $row['lon'],
            ],
            'count' => (int) $row['count'],
            'preview_fileid' => null !== $row['preview_fileid'] ? (int) $row['preview_fileid'] : null,
        ], $res);
    }

    /**
     * Gets preview rows for map cluster markers.
     *
     * @param int[] $fileIds File IDs from {@see getMapClusters} (already folder-filtered)
     */
    public function getMapClusterPreviews(array $fileIds): array
    {
        if ([] === $fileIds) {
            return [];
        }

        $query = $this->connection->getQueryBuilder();
        $files = $query->select('m.fileid', 'm.dayid', 'm.mapcluster', 'm.h', 'm.w', 'm.indexed_at', 'f.etag')
            ->from('memories', 'm')
            ->innerJoin('m', 'filecache', 'f', $query->expr()->eq('m.fileid', 'f.fileid'))
            ->where($query->expr()->in('m.fileid', $query->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        // Post-process
        foreach ($files as &$row) {
            $row['fileid'] = (int) $row['fileid'];
            $row['mapcluster'] = (int) $row['mapcluster'];
            $row['dayid'] = (int) $row['dayid'];
            $row['h'] = (int) $row['h'];
            $row['w'] = (int) $row['w'];
            $row['indexed_at'] = (int) $row['indexed_at'];
        }

        return $files;
    }

    /**
     * Gets the suggested initial coordinates for the map.
     * Uses the coordinates of the newest photo (by date).
     *
     * @psalm-return array{lat: float, lon: float}|null
     */
    public function getMapInitialPosition(): ?array
    {
        $query = $this->connection->getQueryBuilder();

        // SELECT coordinates
        $query->select('m.lat', 'm.lon')
            ->from('memories', 'm')
        ;

        // WHERE this photo is in the user's requested folder recursively
        $query = $this->filterFilecache($query);

        // WHERE this photo has coordinates
        $query->andWhere($query->expr()->andX(
            $query->expr()->isNotNull('m.lat'),
            $query->expr()->isNotNull('m.lon'),
        ));

        // ORDER BY datetaken DESC
        $query->addOrderBy('m.datetaken', 'DESC');

        // LIMIT 1
        $query->setMaxResults(1);

        // FETCH coordinates
        $coords = $this->executeQueryWithCTEs($query)->fetchAssociative();
        if (!$coords) {
            return null;
        }

        return [
            'lat' => (float) $coords['lat'],
            'lon' => (float) $coords['lon'],
        ];
    }
}
