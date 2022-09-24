<?php
declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\IDBConnection;

class TimelineQuery {
    use TimelineQueryDays;
    use TimelineQueryFilters;

    protected IDBConnection $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }
}