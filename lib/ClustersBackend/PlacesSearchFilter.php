<?php

declare(strict_types=1);

namespace OCA\Memories\ClustersBackend;

use OCA\Memories\Db\SQL;
use OCP\DB\QueryBuilder\IQueryBuilder;

final class PlacesSearchFilter
{
    /** @param \Closure(): IQueryBuilder $builder */
    public static function apply(IQueryBuilder $query, \Closure $builder, mixed $value): void
    {
        try {
            $places = RecognizePeopleFilter::parse($value);
        } catch (\InvalidArgumentException $e) {
            throw \OCA\Memories\Exceptions::BadRequest($e->getMessage());
        }
        foreach ($places as $id) {
            $sub = $builder();
            $sub->select($query->expr()->literal(1))->from('memories_places', 'search_place')
                ->where($sub->expr()->eq('search_place.fileid', 'm.fileid'))
                ->andWhere($sub->expr()->eq('search_place.osm_id', $query->createNamedParameter(abs($id), \PDO::PARAM_INT)))
            ;
            $query->andWhere($id < 0 ? SQL::notExists($query, $sub) : SQL::exists($query, $sub));
        }
    }
}
