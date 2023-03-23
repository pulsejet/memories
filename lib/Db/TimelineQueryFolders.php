<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\Files\Folder;
use OCP\IDBConnection;

trait TimelineQueryFolders
{
    protected IDBConnection $connection;

    public function getFolderPreviews(Folder &$folder)
    {
        $query = $this->connection->getQueryBuilder();

        // SELECT all photos
        $query->select('f.fileid', 'f.etag')->from('memories', 'm');

        // WHERE these photos are in the user's requested folder recursively
        $root = new TimelineRoot();
        $root->addFolder($folder);
        $query = $this->joinFilecache($query, $root, true, false);

        // ORDER descending by fileid
        $query->orderBy('f.fileid', 'DESC');

        // MAX 4
        $query->setMaxResults(4);

        // FETCH tag previews
        $rows = $this->executeQueryWithCTEs($query)->fetchAll();

        // Post-process
        foreach ($rows as &$row) {
            $row['fileid'] = (int) $row['fileid'];
        }

        return $rows;
    }
}
