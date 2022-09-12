<?php
declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\IDBConnection;

class TimelineQuery {
    use TimelineQueryDays;
    use TimelineQueryDay;
    use TimelineQueryFavorites;

    protected IDBConnection $connection;

    public function __construct(IDBConnection $connection) {
        $this->connection = $connection;
    }
}