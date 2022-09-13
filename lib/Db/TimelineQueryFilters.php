<?php
declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ITags;

trait TimelineQueryFilters {
    public function transformFavoriteFilter(IQueryBuilder $query, string $userId) {
        // Inner join will filter only the favorites
        $query->innerJoin('m', 'vcategory_to_object', 'fvco', $query->expr()->eq('fvco.objid', 'm.fileid'));

        // Get the favorites category only
        $query->innerJoin('fvco', 'vcategory', 'fvc', $query->expr()->andX(
            $query->expr()->eq('fvc.id', 'fvco.categoryid'),
            $query->expr()->eq('fvc.type', $query->createNamedParameter("files")),
            $query->expr()->eq('fvc.uid', $query->createNamedParameter($userId)),
            $query->expr()->eq('fvc.category', $query->createNamedParameter(ITags::TAG_FAVORITE)),
        ));
    }

    public function addFavoriteTag(IQueryBuilder $query, string $userId) {
        // Inner join will filter only the favorites
        $query->leftJoin('m', 'vcategory_to_object', 'vco', $query->expr()->eq('vco.objid', 'm.fileid'));

        // Get the favorites category only
        $query->leftJoin('vco', 'vcategory', 'vc', $query->expr()->andX(
            $query->expr()->eq('vc.id', 'vco.categoryid'),
            $query->expr()->eq('vc.type', $query->createNamedParameter("files")),
            $query->expr()->eq('vc.uid', $query->createNamedParameter($userId)),
            $query->expr()->eq('vc.category', $query->createNamedParameter(ITags::TAG_FAVORITE)),
        ));
    }

    public function videoFilter(IQueryBuilder $query, string $userId) {
        $query->andWhere($query->expr()->eq('m.isvideo', $query->createNamedParameter('1')));
    }
}