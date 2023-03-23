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

use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IRequest;

class TagsBackend extends Backend
{
    protected TimelineQuery $tq;
    protected IRequest $request;

    public function __construct(TimelineQuery $tq, IRequest $request)
    {
        $this->tq = $tq;
        $this->request = $request;
    }

    public function appName(): string
    {
        return 'Tags';
    }

    public function isEnabled(): bool
    {
        return Util::tagsIsEnabled();
    }

    public function transformDays(IQueryBuilder &$query, bool $aggregate): void
    {
        $tagName = (string) $this->request->getParam('tags');

        $tagId = $this->getSystemTagId($query, $tagName);
        if (false === $tagId) {
            throw new \Exception("Tag {$tagName} not found");
        }

        $query->innerJoin('m', 'systemtag_object_mapping', 'stom', $query->expr()->andX(
            $query->expr()->eq('stom.objecttype', $query->createNamedParameter('files')),
            $query->expr()->eq('stom.objectid', 'm.objectid'),
            $query->expr()->eq('stom.systemtagid', $query->createNamedParameter($tagId)),
        ));
    }

    public function getClusters(): array
    {
        $query = $this->tq->getBuilder();

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
        $query->innerJoin('stom', 'memories', 'm', $query->expr()->eq('m.objectid', 'stom.objectid'));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->joinFilecache($query);

        // GROUP and ORDER by tag name
        $query->groupBy('st.id');
        $query->orderBy($query->createFunction('LOWER(st.name)'), 'ASC');
        $query->addOrderBy('st.id'); // tie-breaker

        // FETCH all tags
        $cursor = $this->tq->executeQueryWithCTEs($query);
        $tags = $cursor->fetchAll();

        // Post process
        foreach ($tags as &$row) {
            $row['id'] = (int) $row['id'];
            $row['count'] = (int) $row['count'];
        }

        return $tags;
    }

    public function getPhotos(string $name, ?int $limit = null): array
    {
        $query = $this->tq->getBuilder();
        $tagId = $this->getSystemTagId($query, $name);
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
        $query->innerJoin('stom', 'memories', 'm', $query->expr()->eq('m.objectid', 'stom.objectid'));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->joinFilecache($query);

        // MAX number of files
        if (null !== $limit) {
            $query->setMaxResults($limit);
        }

        // FETCH tag photos
        return $this->tq->executeQueryWithCTEs($query)->fetchAll();
    }

    private function getSystemTagId(IQueryBuilder $query, string $tagName)
    {
        $sqb = $query->getConnection()->getQueryBuilder();

        return $sqb->select('id')->from('systemtag')->where(
            $sqb->expr()->andX(
                $sqb->expr()->eq('name', $sqb->createNamedParameter($tagName)),
                $sqb->expr()->eq('visibility', $sqb->createNamedParameter(1)),
            )
        )->executeQuery()->fetchOne();
    }
}
