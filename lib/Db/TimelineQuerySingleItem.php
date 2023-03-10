<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

trait TimelineQuerySingleItem
{
    protected IDBConnection $connection;

    public function getSingleItem(int $fileId)
    {
        $query = $this->connection->getQueryBuilder();
        $query->select('m.fileid', ...TimelineQuery::TIMELINE_SELECT)
            ->from('memories', 'm')
            ->where($query->expr()->eq('m.fileid', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
        ;

        // JOIN filecache for etag
        $query->innerJoin('m', 'filecache', 'f', $query->expr()->eq('f.fileid', 'm.fileid'));

        // JOIN with mimetypes to get the mimetype
        $query->join('f', 'mimetypes', 'mimetypes', $query->expr()->eq('f.mimetype', 'mimetypes.id'));

        unset($row['datetaken'], $row['path']);

        return $query->executeQuery()->fetch();
    }
}
