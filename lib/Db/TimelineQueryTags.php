<?php
declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;

trait TimelineQueryTags {
    protected IDBConnection $connection;

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

    public function getTags(Folder $folder) {
        $query = $this->connection->getQueryBuilder();

        // SELECT visible tag name and count of photos
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'), 'count');
        $query->select('st.id', 'st.name', $count)->from('systemtag', 'st')->where(
            $query->expr()->eq('visibility', $query->createNamedParameter(1)),
        );

        // WHERE there are items with this tag
        $query->innerJoin('st', 'systemtag_object_mapping', 'stom', $query->expr()->andX(
            $query->expr()->eq('stom.systemtagid', 'st.id'),
            $query->expr()->eq('stom.objecttype', $query->createNamedParameter("files")),
        ));

        // WHERE these items are memories indexed photos
        $query->innerJoin('stom', 'memories', 'm', $query->expr()->eq('m.fileid', 'stom.objectid'));

        // WHERE these photos are in the user's requested folder recursively
        $query->innerJoin('m', 'filecache', 'f', $this->getFilecacheJoinQuery($query, $folder, true, false));

        // GROUP and ORDER by tag name
        $query->groupBy('st.name');
        $query->orderBy('st.name', 'ASC');

        // FETCH all tags
        $tags = $query->executeQuery()->fetchAll();

        // Post process
        foreach($tags as &$row) {
            $row["id"] = intval($row["id"]);
            $row["count"] = intval($row["count"]);
        }

        return $tags;
    }

    public function getTagPreviews(Folder $folder, int $tagId) {
        $query = $this->connection->getQueryBuilder();

        // SELECT all photos with this tag
        $query->select('f.fileid', 'f.etag')->from('systemtag_object_mapping', 'stom')->where($query->expr()->andX(
            $query->expr()->eq('stom.objecttype', $query->createNamedParameter("files")),
            $query->expr()->eq('stom.systemtagid', $query->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)),
        ));

        // WHERE these items are memories indexed photos
        $query->innerJoin('stom', 'memories', 'm', $query->expr()->eq('m.fileid', 'stom.objectid'));

        // WHERE these photos are in the user's requested folder recursively
        $query->innerJoin('m', 'filecache', 'f', $this->getFilecacheJoinQuery($query, $folder, true, false));

        // MAX 4 results
        $query->setMaxResults(4);

        // FETCH all previews
        $previews = $query->executeQuery()->fetchAll();

        // Post-process
        foreach($previews as &$row) {
            $row["fileid"] = intval($row["fileid"]);
        }

        return $previews;
    }
}