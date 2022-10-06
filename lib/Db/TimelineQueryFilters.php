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

    public function getSystemTagId(IQueryBuilder &$query, string $tagName) {
        $sqb = $query->getConnection()->getQueryBuilder();
        return $sqb->select('id')->from('systemtag')->where(
            $sqb->expr()->andX(
                $sqb->expr()->eq('name', $sqb->createNamedParameter($tagName)),
                $sqb->expr()->eq('visibility', $sqb->createNamedParameter(1)),
            ))->executeQuery()->fetchOne();
    }

    public function transformTagFilter(IQueryBuilder &$query, string $userId, string $tagName) {
        $tagId = $this->getSystemTagId($query, $tagName);
        if ($tagId === FALSE) {
            $tagId = 0; // cannot abort here; that will show up everything in the response
        }

        $query->innerJoin('m', 'systemtag_object_mapping', 'stom', $query->expr()->andX(
            $query->expr()->eq('stom.objecttype', $query->createNamedParameter("files")),
            $query->expr()->eq('stom.objectid', 'm.fileid'),
            $query->expr()->eq('stom.systemtagid', $query->createNamedParameter($tagId)),
        ));
    }
}