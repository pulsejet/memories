<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Varun Patil <radialapps@gmail.com>
 * @author Varun Patil <radialapps@gmail.com>
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\ClustersBackend;

use OCA\Memories\Db\SQL;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IRequest;

class TagsBackend extends Backend
{
    public function __construct(
        protected TimelineQuery $tq,
        protected IRequest $request,
    ) {}

    public static function appName(): string
    {
        return 'Tags';
    }

    public static function clusterType(): string
    {
        return 'tags';
    }

    public function isEnabled(): bool
    {
        return Util::tagsIsEnabled();
    }

    public function transformDayQuery(IQueryBuilder &$query, bool $aggregate): void
    {
        $tagName = (string) $this->request->getParam('tags');

        $tagId = $this->getSystemTagIds($query, [$tagName])[$tagName];

        $query->innerJoin('m', 'systemtag_object_mapping', 'stom', $query->expr()->andX(
            $query->expr()->eq('stom.objecttype', $query->expr()->literal('files')),
            $query->expr()->eq('stom.objectid', 'm.objectid'),
            $query->expr()->eq('stom.systemtagid', $query->createNamedParameter($tagId)),
        ));
    }

    public function getClustersInternal(int $fileid = 0): array
    {
        if ($fileid) {
            throw new \Exception('TagsBackend: fileid filter not implemented');
        }

        $query = $this->tq->getBuilder();

        // SELECT visible tag name and count of photos
        $count = $query->func()->count(SQL::distinct($query, 'm.fileid'), 'count');
        $query->select('st.id', 'st.name', $count)
            ->from('systemtag', 'st')
            ->where($query->expr()->eq('st.visibility', $query->expr()->literal(1, \PDO::PARAM_INT)))
        ;

        // WHERE there are items with this tag
        $query->innerJoin('st', 'systemtag_object_mapping', 'stom', $query->expr()->andX(
            $query->expr()->eq('stom.objecttype', $query->expr()->literal('files')),
            $query->expr()->eq('stom.systemtagid', 'st.id'),
        ));

        // WHERE these items are memories indexed photos
        $query->innerJoin('stom', 'memories', 'm', $query->expr()->eq('m.objectid', 'stom.objectid'));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->filterFilecache($query);

        // GROUP and ORDER by tag name
        $query->addGroupBy('st.id');
        $query->addOrderBy($query->func()->lower('st.name'), 'ASC');
        $query->addOrderBy('st.id'); // tie-breaker

        // SELECT cover photo
        $query = SQL::materialize($query, 'st');
        Covers::selectCover(
            query: $query,
            type: self::clusterType(),
            clusterTable: 'st',
            clusterTableId: 'id',
            objectTable: 'systemtag_object_mapping',
            objectTableObjectId: 'objectid',
            objectTableClusterId: 'systemtagid',
        );

        // SELECT etag for the cover
        $query = SQL::materialize($query, 'st');
        $this->tq->selectEtag($query, 'st.cover', 'cover_etag');

        // FETCH all tags
        $tags = $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];

        // Post process
        foreach ($tags as &$row) {
            $row['id'] = (int) $row['id'];
            $row['count'] = (int) $row['count'];
        }

        return $tags;
    }

    public static function getClusterId(array $cluster): int|string
    {
        return $cluster['name'];
    }

    public function getPhotos(string $name, ?int $limit = null, ?int $fileid = null): array
    {
        $query = $this->tq->getBuilder();
        $tagId = $this->getSystemTagIds($query, [$name])[$name];

        // SELECT all photos with this tag
        $query->select('f.fileid', 'f.etag', 'stom.systemtagid')
            ->from('systemtag_object_mapping', 'stom')
            ->where(
                $query->expr()->eq('stom.objecttype', $query->expr()->literal('files')),
                $query->expr()->eq('stom.systemtagid', $query->createNamedParameter($tagId)),
            )
        ;

        // WHERE these items are memories indexed photos
        $query->innerJoin('stom', 'memories', 'm', $query->expr()->eq('m.objectid', 'stom.objectid'));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->filterFilecache($query);

        // JOIN with the filecache table
        $query->innerJoin('m', 'filecache', 'f', $query->expr()->eq('f.fileid', 'm.fileid'));

        // MAX number of files
        if (-6 === $limit) {
            Covers::filterCover($query, self::clusterType(), 'stom', 'objectid', 'systemtagid');
        } elseif (null !== $limit) {
            $query->setMaxResults($limit);
        }

        // Filter by fileid if specified
        if (null !== $fileid) {
            $query->andWhere($query->expr()->eq('f.fileid', $query->createNamedParameter($fileid, \PDO::PARAM_INT)));
        }

        // FETCH tag photos
        return $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];
    }

    public function getClusterIdFrom(array $photo): int
    {
        return (int) $photo['systemtagid'];
    }

    /**
     * Get the systemtag id for a given tag name.
     *
     * @param IQueryBuilder $query    Query builder
     * @param string[]      $tagNames List of tag names
     *
     * @return array Map from tag name to tag id
     */
    private function getSystemTagIds(IQueryBuilder $query, array $tagNames): array
    {
        $sqb = $query->getConnection()->getQueryBuilder();

        $res = $sqb->select('id', 'name')->from('systemtag')->where(
            $sqb->expr()->andX(
                $sqb->expr()->in('name', $sqb->createNamedParameter($tagNames, IQueryBuilder::PARAM_STR_ARRAY)),
                $sqb->expr()->eq('visibility', $sqb->expr()->literal(1, IQueryBuilder::PARAM_INT)),
            ),
        )->executeQuery()->fetchAll();

        // Create result map
        $map = array_fill_keys($tagNames, 0);
        foreach ($res as $row) {
            $map[$row['name']] = (int) $row['id'];
        }

        // Required to have all tags in the result
        foreach ($tagNames as $tagName) {
            if (0 === $map[$tagName]) {
                throw new \Exception("Tag {$tagName} not found");
            }
        }

        return $map;
    }
}
