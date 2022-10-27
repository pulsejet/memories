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
        $query = $this->joinFilecache($query, $folder, true, false);

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

    public function getTagPreviews(array &$tags, Folder &$folder)
    {
        foreach ($tags as &$tag) {
            $query = $this->connection->getQueryBuilder();

            // SELECT all photos with this tag
            $query->select('f.fileid', 'f.etag')->from(
                'systemtag_object_mapping',
                'stom'
            )->where(
                $query->expr()->eq('stom.objecttype', $query->createNamedParameter('files')),
                $query->expr()->eq('stom.systemtagid', $query->createNamedParameter($tag['id'])),
            );

            // WHERE these items are memories indexed photos
            $query->innerJoin('stom', 'memories', 'm', $query->expr()->eq('m.fileid', 'stom.objectid'));

            // WHERE these photos are in the user's requested folder recursively
            $query = $this->joinFilecache($query, $folder, true, false);

            // MAX 4
            $query->setMaxResults(4);

            // FETCH tag previews
            $cursor = $this->executeQueryWithCTEs($query);
            $tag['previews'] = $cursor->fetchAll();

            // Post-process
            foreach ($tag['previews'] as &$row) {
                $row['fileid'] = (int) $row['fileid'];
            }
        }
    }
}
