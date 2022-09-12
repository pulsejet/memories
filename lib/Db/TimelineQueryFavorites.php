<?php
declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ITags;

trait TimelineQueryFavorites {
    public function transformFavoriteFilter(IQueryBuilder $query, string $userId) {
        // Inner join will filter only the favorites
        $query->innerJoin('m', 'vcategory_to_object', 'vco', $query->expr()->eq('vco.objid', 'm.fileid'));

        // Get the favorites category only
        $query->innerJoin('vco', 'vcategory', 'vc', $query->expr()->andX(
            $query->expr()->eq('vc.id', 'vco.categoryid'),
            $query->expr()->eq('vc.uid', $query->createNamedParameter($userId)),
            $query->expr()->eq('vc.category', $query->createNamedParameter(ITags::TAG_FAVORITE)),
        ));
    }
}