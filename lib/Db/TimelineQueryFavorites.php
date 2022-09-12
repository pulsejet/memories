<?php
declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

trait TimelineQueryFavorites {
    public function transformFavoriteFilter(IQueryBuilder $query) {
        // TODO: 2 is not guaranteed to be the favorites tag id
        // use OCP\ITags; instead
        $query->innerJoin('m', 'vcategory_to_object', 'c',
            $query->expr()->andX(
                $query->expr()->eq('c.objid', 'm.fileid'),
                $query->expr()->eq('c.categoryid', $query->createNamedParameter(2, IQueryBuilder::PARAM_INT)),
                $query->expr()->eq('c.type', $query->createNamedParameter('files')),
            ));
    }
}