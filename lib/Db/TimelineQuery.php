<?php
declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\IDBConnection;

class TimelineQuery {
    use TimelineQueryDays;
    use TimelineQueryFilters;
    use TimelineQueryTags;

    protected IDBConnection $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }

    public function getInfoById(int $id): array {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('fileid', 'dayid', 'datetaken')
            ->from('memories')
            ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($id, \PDO::PARAM_INT)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        $utcTs = 0;
        try {
            $utcDate = new \DateTime($row['datetaken'], new \DateTimeZone('UTC'));
            $utcTs = $utcDate->getTimestamp();
        } catch (\Throwable $e) {}

        return [
            'fileid' => intval($row['fileid']),
            'dayid' => intval($row['dayid']),
            'datetaken' => $utcTs,
        ];
    }
}