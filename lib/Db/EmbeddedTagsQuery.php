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

namespace OCA\Memories\Db;

use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;

class EmbeddedTagsQuery
{
    use EmbeddedTagsQueryFilters;

    public const TAGS_SELECT = [
        'id', 'user_id', 'tag', 'parent_tag_id', 
        'path', 'level', 'created_at'
    ];

    public function __construct(
        protected IDBConnection $connection,
        protected IRequest $request,
        protected IUserManager $userManager,
    ) {}

    public function getBuilder(): IQueryBuilder
    {
        return $this->connection->getQueryBuilder();
    }

    /**
     * Get all tags for a user in flat manner with optional filtering and pagination
     *
     * @param string|null $pattern Optional regex pattern to filter tags
     * @param int|null $limit Optional limit for pagination
     * @param int|null $offset Optional offset for pagination
     * @return array List of tags
     */
    public function getTagsFlat(?string $pattern = null, ?int $limit = null, ?int $offset = null): array
    {
        $query = $this->getBuilder();
        
        $query->select(self::TAGS_SELECT)
            ->from('memories_embedded_tags', 'et')
            ->where($query->expr()->eq('user_id', $query->createNamedParameter(Util::getUID())))
            ->orderBy('path', 'ASC');

        // Apply pattern filter if provided
        if ($pattern !== null) {
            $this->transformPatternFilter($query, $pattern);
        }

        // Apply pagination if provided
        if ($limit !== null) {
            $query->setMaxResults($limit);
        }
        if ($offset !== null) {
            $query->setFirstResult($offset);
        }

        return $query->executeQuery()->fetchAll() ?: [];
    }

    /**
     * Get all tags for a user in hierarchical structure
     *
     * @param string|null $pattern Optional regex pattern to filter tags
     * @return array Hierarchical structure of tags
     */
    public function getTagsHierarchical(?string $pattern = null): array
    {
        // Get all tags first
        $query = $this->getBuilder();
        
        $query->select(self::TAGS_SELECT)
            ->from('memories_embedded_tags', 'et')
            ->where($query->expr()->eq('user_id', $query->createNamedParameter(Util::getUID())))
            ->orderBy('level', 'ASC')
            ->addOrderBy('tag', 'ASC');

        // Apply pattern filter if provided
        if ($pattern !== null) {
            $this->transformPatternFilter($query, $pattern);
        }

        $allTags = $query->executeQuery()->fetchAll() ?: [];
        
        // Build hierarchical structure
        return $this->buildHierarchy($allTags);
    }

    /**
     * Get count of tags matching pattern
     *
     * @param string|null $pattern Optional regex pattern to filter tags
     * @return int Number of tags
     */
    public function getTagsCount(?string $pattern = null): int
    {
        $query = $this->getBuilder();
        
        $query->select($query->func()->count('*', 'count'))
            ->from('memories_embedded_tags', 'et')
            ->where($query->expr()->eq('user_id', $query->createNamedParameter(Util::getUID())));

        // Apply pattern filter if provided
        if ($pattern !== null) {
            $this->transformPatternFilter($query, $pattern);
        }

        $result = $query->executeQuery()->fetch();
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Build hierarchical structure from flat tags array
     *
     * @param array $tags Flat array of tags
     * @return array Hierarchical structure
     */
    private function buildHierarchy(array $tags): array
    {
        $hierarchy = [];
        $idMap = [];

        // First pass: create nodes and map by ID
        foreach ($tags as $tag) {
            $node = [
                'id' => $tag['id'],
                'tag' => $tag['tag'],
                'path' => $tag['path'],
                'level' => $tag['level'],
                'created_at' => $tag['created_at'],
                'children' => []
            ];
            $idMap[$tag['id']] = &$node;
        }

        // Second pass: build hierarchy
        foreach ($tags as $tag) {
            if ($tag['parent_tag_id'] === null) {
                // Root level tag
                $hierarchy[] = &$idMap[$tag['id']];
            } else {
                // Child tag
                if (isset($idMap[$tag['parent_tag_id']])) {
                    $idMap[$tag['parent_tag_id']]['children'][] = &$idMap[$tag['id']];
                }
            }
        }

        return $hierarchy;
    }
} 