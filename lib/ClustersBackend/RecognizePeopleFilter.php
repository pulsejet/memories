<?php

declare(strict_types=1);

namespace OCA\Memories\ClustersBackend;

use OCA\Memories\Db\SQL;
use OCP\DB\QueryBuilder\IQueryBuilder;

final class RecognizePeopleFilter
{
    /** @return list<int> */
    public static function parse(mixed $value): array
    {
        if (null === $value || '' === $value) {
            return [];
        }
        if (!\is_string($value) || \strlen($value) > 160) {
            throw new \InvalidArgumentException('Invalid people filter');
        }
        $values = explode(',', $value);
        if (\count($values) > 8) {
            throw new \InvalidArgumentException('Too many people');
        }
        foreach ($values as $id) {
            if (!preg_match('/^[1-9][0-9]{0,17}$/D', $id)) {
                throw new \InvalidArgumentException('Invalid person ID');
            }
        }

        return array_values(array_unique(array_map(intval(...), $values)));
    }

    /** @param \Closure(): IQueryBuilder $builder */
    public static function apply(IQueryBuilder $query, \Closure $builder, string $uid, mixed $value): void
    {
        try {
            $people = self::parse($value);
        } catch (\InvalidArgumentException $e) {
            throw \OCA\Memories\Exceptions::BadRequest($e->getMessage());
        }
        foreach ($people as $id) {
            $sub = $builder();
            $sub->select($query->expr()->literal(1))
                ->from('recognize_face_detections', 'people_detection')
                ->innerJoin(
                    'people_detection',
                    'recognize_face_clusters',
                    'people_cluster',
                    $sub->expr()->eq('people_cluster.id', 'people_detection.cluster_id'),
                )
                ->where($sub->expr()->eq('people_detection.file_id', 'm.fileid'))
                ->andWhere($sub->expr()->eq('people_cluster.id', $query->createNamedParameter($id, \PDO::PARAM_INT)))
                ->andWhere($sub->expr()->eq('people_cluster.user_id', $query->createNamedParameter($uid)))
                ->andWhere($sub->expr()->eq('people_detection.user_id', $query->createNamedParameter($uid)))
            ;
            $query->andWhere(SQL::exists($query, $sub));
        }
    }
}
