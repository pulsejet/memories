<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ITags;

trait TimelineQueryFilters
{
    public function transformFavoriteFilter(IQueryBuilder &$query, string $userId)
    {
        $query->innerJoin('m', 'vcategory_to_object', 'vcoi', $query->expr()->andX(
            $query->expr()->eq('vcoi.objid', 'm.fileid'),
            $query->expr()->in('vcoi.categoryid', $this->getFavoriteVCategoryFun($query, $userId)),
        ));
    }

    public function addFavoriteTag(IQueryBuilder &$query, string $userId)
    {
        $query->leftJoin('m', 'vcategory_to_object', 'vco', $query->expr()->andX(
            $query->expr()->eq('vco.objid', 'm.fileid'),
            $query->expr()->in('vco.categoryid', $this->getFavoriteVCategoryFun($query, $userId)),
        ));
        $query->addSelect('vco.categoryid');
    }

    public function transformVideoFilter(IQueryBuilder &$query, string $userId)
    {
        $query->andWhere($query->expr()->eq('m.isvideo', $query->createNamedParameter('1')));
    }

    public function transformLimitDay(IQueryBuilder &$query, string $userId, int $limit)
    {
        // The valid range for limit is 1 - 100; otherwise abort
        if ($limit < 1 || $limit > 100) {
            return;
        }
        $query->setMaxResults($limit);
    }

    public function transformBoundFilter(IQueryBuilder &$query, string $userId, array $bounds)
    {
        $query->andWhere(
            $query->expr()->andX(
                $query->expr()->gte('m.lat', $query->createNamedParameter($bounds[0], IQueryBuilder::PARAM_STR)),
                $query->expr()->lte('m.lat', $query->createNamedParameter($bounds[1], IQueryBuilder::PARAM_STR)),
                $query->expr()->gte('m.lon', $query->createNamedParameter($bounds[2], IQueryBuilder::PARAM_STR)),
                $query->expr()->lte('m.lon', $query->createNamedParameter($bounds[3], IQueryBuilder::PARAM_STR))
            )
        );
    }

    private function applyAllTransforms(array $transforms, IQueryBuilder &$query, string $uid): void
    {
        foreach ($transforms as &$transform) {
            $fun = \array_slice($transform, 0, 2);
            $params = \array_slice($transform, 2);
            array_unshift($params, $uid);
            array_unshift($params, $query);
            $fun(...$params);
        }
    }

    private function getFavoriteVCategoryFun(IQueryBuilder &$query, string $userId)
    {
        return $query->createFunction(
            $query->getConnection()->getQueryBuilder()->select('id')->from('vcategory', 'vc')->where(
                $query->expr()->andX(
                    $query->expr()->eq('type', $query->createNamedParameter('files')),
                    $query->expr()->eq('uid', $query->createNamedParameter($userId)),
                    $query->expr()->eq('category', $query->createNamedParameter(ITags::TAG_FAVORITE)),
                )
            )->getSQL()
        );
    }
}
