<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

trait TimelineQueryNativeX
{
    use TimelineQueryBase;

    public function transformNativeQuery(IQueryBuilder &$query, bool $aggregate): void
    {
        if (!$aggregate) {
            $query->addSelect('m.buid');
        }
    }
}
