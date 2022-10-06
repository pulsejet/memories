<?php
declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ITags;

trait TimelineQueryFilters {
    private function applyAllTransforms(array $transforms, IQueryBuilder &$query, string $uid): void {
        foreach ($transforms as &$transform) {
            $fun = array_slice($transform, 0, 2);
            $params = array_slice($transform, 2);
            array_unshift($params, $uid);
            array_unshift($params, $query);
            $fun(...$params);
        }
    }

    public function transformFavoriteFilter(IQueryBuilder &$query, string $userId) {
        $query->innerJoin('m', 'vcategory_to_object', 'vcoi', $query->expr()->andX(
            $query->expr()->eq('vcoi.objid', 'm.fileid'),
            $query->expr()->in('vcoi.categoryid', $this->getFavoriteVCategoryFun($query, $userId)),
        ));
    }

    public function addFavoriteTag(IQueryBuilder &$query, string $userId) {
        $query->leftJoin('m', 'vcategory_to_object', 'vco', $query->expr()->andX(
            $query->expr()->eq('vco.objid', 'm.fileid'),
            $query->expr()->in('vco.categoryid', $this->getFavoriteVCategoryFun($query, $userId)),
        ));
    }

    private function getFavoriteVCategoryFun(IQueryBuilder &$query, string $userId) {
        return $query->createFunction(
            $query->getConnection()->getQueryBuilder()->select('id')->from('vcategory', 'vc')->where(
                $query->expr()->andX(
                    $query->expr()->eq('type', $query->createNamedParameter("files")),
                    $query->expr()->eq('uid', $query->createNamedParameter($userId)),
                    $query->expr()->eq('category', $query->createNamedParameter(ITags::TAG_FAVORITE)),
                ))->getSQL());
    }

    public function transformVideoFilter(IQueryBuilder &$query, string $userId) {
        $query->andWhere($query->expr()->eq('m.isvideo', $query->createNamedParameter('1')));
    }
}