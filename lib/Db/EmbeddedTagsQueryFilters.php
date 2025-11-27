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

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

trait EmbeddedTagsQueryFilters
{
    protected IDBConnection $connection;

    /**
     * Transform query to filter by pattern using LIKE or REGEXP
     *
     * @param IQueryBuilder $query
     * @param string $pattern Pattern to search for
     */
    public function transformPatternFilter(IQueryBuilder &$query, string $pattern): void
    {
        // Sanitize pattern input
        $pattern = trim($pattern);
        if (empty($pattern)) {
            return;
        }

        // Try to determine if this is a regex pattern or simple search
        if ($this->isRegexPattern($pattern)) {
            // Use REGEXP for MySQL/MariaDB or similar for other databases
            $dbType = $this->connection->getDatabasePlatform()->getName();
            
            if (in_array($dbType, ['mysql', 'mariadb'], true)) {
                $query->andWhere($query->expr()->orX(
                    $query->createFunction("et.tag REGEXP " . $query->createNamedParameter($pattern)),
                    $query->createFunction("et.path REGEXP " . $query->createNamedParameter($pattern))
                ));
            } elseif ($dbType === 'postgresql') {
                $query->andWhere($query->expr()->orX(
                    $query->createFunction("et.tag ~ " . $query->createNamedParameter($pattern)),
                    $query->createFunction("et.path ~ " . $query->createNamedParameter($pattern))
                ));
            } else {
                // Fallback to LIKE for SQLite and others
                $likePattern = '%' . $this->escapeLikePattern($pattern) . '%';
                $query->andWhere($query->expr()->orX(
                    $query->expr()->like('et.tag', $query->createNamedParameter($likePattern)),
                    $query->expr()->like('et.path', $query->createNamedParameter($likePattern))
                ));
            }
        } else {
            // Use LIKE for simple text search
            $likePattern = '%' . $this->escapeLikePattern($pattern) . '%';
            $query->andWhere($query->expr()->orX(
                $query->expr()->like('et.tag', $query->createNamedParameter($likePattern)),
                $query->expr()->like('et.path', $query->createNamedParameter($likePattern))
            ));
        }
    }

    /**
     * Apply limit transformation for pagination
     *
     * @param IQueryBuilder $query
     * @param int $limit Maximum number of results
     */
    public function transformLimit(IQueryBuilder &$query, int $limit): void
    {
        if ($limit >= 1 && $limit <= 1000) { // Allow larger limits for tags
            $query->setMaxResults($limit);
        }
    }

    /**
     * Apply offset transformation for pagination
     *
     * @param IQueryBuilder $query
     * @param int $offset Number of results to skip
     */
    public function transformOffset(IQueryBuilder &$query, int $offset): void
    {
        if ($offset >= 0) {
            $query->setFirstResult($offset);
        }
    }

    /**
     * Check if the pattern looks like a regex
     *
     * @param string $pattern
     * @return bool
     */
    private function isRegexPattern(string $pattern): bool
    {
        // Simple heuristic: check for common regex characters
        return preg_match('/[.*+?^${}()|[\]\\\\]/', $pattern) === 1;
    }

    /**
     * Escape special characters for LIKE pattern
     *
     * @param string $pattern
     * @return string
     */
    private function escapeLikePattern(string $pattern): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $pattern);
    }
} 