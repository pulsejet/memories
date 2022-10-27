<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\IDBConnection;

trait TimelineQueryTags
{
    protected IDBConnection $connection;

    public function getSystemTagId(IQueryBuilder &$query, string $tagName)
    {
        $sqb = $query->getConnection()->getQueryBuilder();

        return $sqb->select('id')->from('systemtag')->where(
            $sqb->expr()->andX(
                $sqb->expr()->eq('name', $sqb->createNamedParameter($tagName)),
                $sqb->expr()->eq('visibility', $sqb->createNamedParameter(1)),
            )
        )->executeQuery()->fetchOne();
    }

    public function transformTagFilter(IQueryBuilder &$query, string $userId, string $tagName)
    {
        $tagId = $this->getSystemTagId($query, $tagName);
        if (false === $tagId) {
            throw new \Exception("Tag {$tagName} not found");
        }

        $query->innerJoin('m', 'systemtag_object_mapping', 'stom', $query->expr()->andX(
            $query->expr()->eq('stom.objecttype', $query->createNamedParameter('files')),
            $query->expr()->eq('stom.objectid', 'm.fileid'),
            $query->expr()->eq('stom.systemtagid', $query->createNamedParameter($tagId)),
        ));
    }

    public function getTags(Folder $folder)
    {
        $query = $this->connection->getQueryBuilder();

        // SELECT visible tag name and count of photos
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'), 'count');
        $query->select('st.id', 'st.name', $count)->from('systemtag', 'st')->where(
            $query->expr()->eq('visibility', $query->createNamedParameter(1)),
        );

        // WHERE there are items with this tag
        $query->innerJoin('st', 'systemtag_object_mapping', 'stom', $query->expr()->andX(
            $query->expr()->eq('stom.objecttype', $query->createNamedParameter('files')),
            $query->expr()->eq('stom.systemtagid', 'st.id'),
        ));

        // WHERE these items are memories indexed photos
        $query->innerJoin('stom', 'memories', 'm', $query->expr()->eq('m.fileid', 'stom.objectid'));

        // WHERE these photos are in the user's requested folder recursively
        // This is a hack to speed up the query instead of using joinFilecache
        // The problem is objectid is VARCHAR(64) and fileid is BIGINT(20), so a
        // join is extremely slow. Instead, we use a subquery to check existence.
        //
        // https://blog.sqlauthority.com/2010/06/05/sql-server-convert-in-to-exists-performance-talk/

        $this->addSubfolderJoinParams($query, $folder, false);
        $query->innerJoin('m', 'filecache', 'f', $query->expr()->andX(
            $query->expr()->eq('f.fileid', 'm.fileid'),
            $query->createFunction('EXISTS (SELECT 1 from *PREFIX*cte_folders WHERE *PREFIX*cte_folders.fileid = `f`.parent)')
        ));

        // GROUP and ORDER by tag name
        $query->groupBy('st.name');
        $query->orderBy('st.name', 'ASC');
        $query->addOrderBy('st.id'); // tie-breaker

        // FETCH all tags
        $cursor = $this->executeQueryWithCTEs($query);
        $tags = $cursor->fetchAll();

        // Post process
        foreach ($tags as &$row) {
            $row['id'] = (int) $row['id'];
            $row['count'] = (int) $row['count'];
        }

        return $tags;
    }

    public function getTagPreviews(string $tagName, Folder &$folder)
    {
        $query = $this->connection->getQueryBuilder();
        $tagId = $this->getSystemTagId($query, $tagName);
        if (false === $tagId) {
            return [];
        }

        // SELECT all photos with this tag
        $query->select('f.fileid', 'f.etag', 'stom.systemtagid')->from(
            'systemtag_object_mapping',
            'stom'
        )->where(
            $query->expr()->eq('stom.objecttype', $query->createNamedParameter('files')),
            $query->expr()->eq('stom.systemtagid', $query->createNamedParameter($tagId)),
        );

        // WHERE these items are memories indexed photos
        $query->innerJoin('stom', 'memories', 'm', $query->expr()->eq('m.fileid', 'stom.objectid'));

        // WHERE these photos are in the user's requested folder recursively
        // See the function above for an explanation of this hack
        $this->addSubfolderJoinParams($query, $folder, false);
        $query->innerJoin('m', 'filecache', 'f', $query->expr()->andX(
            $query->expr()->eq('f.fileid', 'm.fileid'),
            $query->createFunction('EXISTS (SELECT 1 from *PREFIX*cte_folders WHERE *PREFIX*cte_folders.fileid = `f`.parent)')
        ));

        // MAX 4
        $query->setMaxResults(4);

        // FETCH tag previews
        $cursor = $this->executeQueryWithCTEs($query);
        $ans = $cursor->fetchAll();

        // Post-process
        foreach ($ans as &$row) {
            $row['fileid'] = (int) $row['fileid'];
        }

        return $ans;
    }
}
