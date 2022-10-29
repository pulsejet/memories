<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class TimelineQuery
{
    use TimelineQueryAlbums;
    use TimelineQueryDays;
    use TimelineQueryFaces;
    use TimelineQueryFilters;
    use TimelineQueryTags;

    protected IDBConnection $connection;

    public function __construct(IDBConnection $connection)
    {
        $this->connection = $connection;
    }

    public static function debugQuery(IQueryBuilder &$query, string $sql = '')
    {
        // Print the query and exit
        $sql = empty($sql) ? $query->getSQL() : $sql;
        $sql = str_replace('*PREFIX*', 'oc_', $sql);
        $sql = self::replaceQueryParams($query, $sql);
        echo "{$sql}";

        exit;
    }

    public static function replaceQueryParams(IQueryBuilder &$query, string $sql)
    {
        $params = $query->getParameters();
        foreach ($params as $key => $value) {
            $sql = str_replace(':'.$key, $query->getConnection()->getDatabasePlatform()->quoteStringLiteral($value), $sql);
        }

        return $sql;
    }

    public function transformExtraFields(IQueryBuilder &$query, string $uid, array &$fields)
    {
    }

    public function getInfoById(int $id): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('fileid', 'dayid', 'datetaken')
            ->from('memories')
            ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($id, \PDO::PARAM_INT)))
        ;

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        $utcTs = 0;

        try {
            $utcDate = new \DateTime($row['datetaken'], new \DateTimeZone('UTC'));
            $utcTs = $utcDate->getTimestamp();
        } catch (\Throwable $e) {
        }

        return [
            'fileid' => (int) $row['fileid'],
            'dayid' => (int) $row['dayid'],
            'datetaken' => $utcTs,
        ];
    }
}
