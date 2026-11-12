<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

trait TimelineQueryCTE
{
    use TimelineQueryBase;

    /**
     * Run a query referencing cte_folders, prepending the WITH clause as needed.
     *
     * Hidden folders are excluded unless the cteIncludeHidden parameter is set.
     */
    public function executeQueryWithCTEs(IQueryBuilder $query, string $psql = ''): \OCP\DB\IResult
    {
        $sql = empty($psql) ? $query->getSQL() : $psql;
        $params = $query->getParameters();
        $types = $query->getParameterTypes();

        // Add WITH clause if needed
        $acl = CTEParams::hasAcl($query) ? CTEParams::aclMappingClause($query, 'acl_a') : null;
        if (str_contains($sql, 'cte_folders')) {
            $aclTops = CTEParams::hasAclDeniedTops($query);
            $CTE_SQL = CTEParams::isFoldersArchive($query)
                ? $this->CTE_FOLDERS_ARCHIVE($acl, $aclTops)
                : $this->CTE_FOLDERS(
                    CTEParams::isIncludeHidden($query),
                    CTEParams::getBlocklistCount($query),
                    $acl,
                    $aclTops,
                );
            $sql = $CTE_SQL.' '.$sql;
        } elseif (str_contains($sql, 'cte_file_parents')) {
            $CTE_SQL = $this->CTE_FILE_PARENTS();
            $sql = $CTE_SQL.' '.$sql;
        } elseif (null !== $acl && str_contains($sql, 'cte_acl')) {
            $CTE_SQL = self::bundleCTEs([$this->CTE_ACL($acl)]);
            $sql = $CTE_SQL.' '.$sql;
        }

        return $this->connection->executeQuery($sql, $params, $types);
    }

    /**
     * @param int    $count number of bound :fnBlocklistN patterns
     * @param string $alias table alias holding the name column
     */
    public function blocklistClause(int $count, string $alias): string
    {
        if (0 === $count) {
            return '1 = 0';
        }

        $parts = [];
        for ($i = 0; $i < $count; ++$i) {
            $parts[] = "({$alias}.name LIKE :fnBlocklist{$i} ESCAPE :fnBlocklistEscape)";
        }

        return implode(' OR ', $parts);
    }

    /**
     * CTE to get all files recursively in the given top folders
     * :topFolderIds - The top folders to get files from.
     * :fnBlocklistN - File or folder name LIKE patterns to prune.
     *
     * @param bool        $hidden         Whether to include files in hidden folders
     *                                    If the top folder is hidden, the files in it will still be returned
     *                                    Hidden files are marked as such in the "hidden" field
     * @param int         $blocklistCount Number of blocklist patterns
     * @param null|string $acl            Clause matching the ACL rules of the user, if any apply
     * @param bool        $aclTops        Whether some top folders are not readable (:cteAclDeniedTops)
     */
    protected function CTE_FOLDERS_ALL(bool $hidden, int $blocklistCount, ?string $acl, bool $aclTops): string
    {
        $provider = $this->connection->getDatabaseProvider();

        // Filter out folder MIME types
        $FOLDER_MIME_QUERY = "SELECT MAX(id) FROM *PREFIX*mimetypes WHERE mimetype = 'httpd/unix-directory'";

        // Select 1 if there is a file in the folder with the specified name
        $HAS_FILE = static fn (string $name): string => "SELECT 1 FROM *PREFIX*filecache f2
            WHERE (f2.parent = f.fileid)
            AND (f2.name = '{$name}')";

        // Check for nomedia and nomemories files
        // Two separate subqueries can actually be faster here (up to 10x on MariaDB)
        $HAS_NOMEDIA = $HAS_FILE('.nomedia');
        $HAS_NOMEMORIES = $HAS_FILE('.nomemories');
        $CLS_NOMEDIA = "NOT EXISTS ({$HAS_NOMEDIA}) AND NOT EXISTS ({$HAS_NOMEMORIES})";

        // Whether to filter out hidden folders
        $CLS_HIDDEN_JOIN = $hidden ? '1 = 1' : "f.name NOT LIKE '.%'";

        // Blocklisted names prune the whole subtree
        $CLS_BLOCKLIST = $this->blocklistClause($blocklistCount, 'f');

        // On MySQL or MariaDB, provide the hint to use the index
        // The index is not used sometimes since the table is unbalanced
        // and fs_parent is used instead
        $UNION_INDEX_HINT = IDBConnection::PLATFORM_MYSQL === $provider
            ? 'USE INDEX (memories_parent_mimetype)'
            : '';

        // ACL: a folder is readable by its own rule, or else if its parent is.
        // Unreadable folders are still walked, a rule further down can allow again.
        $CLS_READABLE_TOP = null !== $acl && $aclTops
            ? 'CASE WHEN f.fileid IN (:cteAclDeniedTops) THEN 0 ELSE 1 END'
            : '1';
        $CLS_READABLE = null !== $acl
            ? 'COALESCE(CASE WHEN acl.rmask = 1 THEN acl.rperm END, c.readable)'
            : '1';
        $ACL_JOIN = null !== $acl
            ? 'LEFT JOIN *PREFIX*cte_acl acl ON (acl.fileid = f.fileid)'
            : '';

        return
        "*PREFIX*cte_folders_all(fileid, name, hidden, readable) AS (
            SELECT f.fileid, f.name,
                (0) AS hidden,
                ({$CLS_READABLE_TOP}) AS readable
            FROM *PREFIX*filecache f
            WHERE (
                (f.fileid IN (:topFolderIds)) AND
                ({$CLS_NOMEDIA}) AND
                (NOT ({$CLS_BLOCKLIST}))
            )

            UNION ALL

            SELECT f.fileid, f.name,
                (CASE WHEN c.hidden = 1 OR f.name LIKE '.%' THEN 1 ELSE 0 END) AS hidden,
                ({$CLS_READABLE}) AS readable
            FROM *PREFIX*filecache f
            {$UNION_INDEX_HINT}
            INNER JOIN *PREFIX*cte_folders_all c
                ON (
                    f.parent = c.fileid AND
                    f.mimetype = ({$FOLDER_MIME_QUERY}) AND
                    ({$CLS_HIDDEN_JOIN})
                )
            {$ACL_JOIN}
            WHERE (
                ({$CLS_NOMEDIA}) AND
                (NOT ({$CLS_BLOCKLIST}))
            )
        )";
    }

    /**
     * CTE to get all folders recursively in the given top folders.
     *
     * @param bool        $hidden         Whether to include files in hidden folders
     * @param int         $blocklistCount Number of blocklist patterns
     * @param null|string $acl            Clause matching the ACL rules of the user, if any apply
     * @param bool        $aclTops        Whether some top folders are not readable
     */
    protected function CTE_FOLDERS(bool $hidden, int $blocklistCount, ?string $acl, bool $aclTops): string
    {
        $CLS_HIDDEN = $hidden ? 'MIN(hidden)' : '0';

        $cte = "*PREFIX*cte_folders AS (
            SELECT
                fileid, ({$CLS_HIDDEN}) AS hidden, MAX(readable) AS readable
            FROM
                *PREFIX*cte_folders_all
            GROUP BY
                fileid
        )";

        return self::bundleCTEs([
            ...(null !== $acl ? [$this->CTE_ACL($acl)] : []),
            $this->CTE_FOLDERS_ALL($hidden, $blocklistCount, $acl, $aclTops),
            $cte,
        ]);
    }

    /**
     * CTE with the read permission the group folder ACL rules give the user,
     * for each file and folder with a rule that applies to them.
     *
     * Rules on the same path merge with allow winning over deny. rmask is 1
     * if any rule sets the read bit, and rperm is then whether reading is
     * allowed; otherwise it is inherited.
     *
     * @param string $acl Clause matching the ACL rules of the user
     */
    protected function CTE_ACL(string $acl): string
    {
        return "*PREFIX*cte_acl(fileid, rmask, rperm) AS (
            SELECT
                acl_a.fileid,
                MAX(acl_a.mask & 1),
                MAX(acl_a.permissions & acl_a.mask & 1)
            FROM
                *PREFIX*group_folders_acl acl_a
            WHERE
                {$acl}
            GROUP BY
                acl_a.fileid
        )";
    }

    /**
     * CTE to get all archive folders recursively in the given top folders.
     *
     * @param null|string $acl     Clause matching the ACL rules of the user, if any apply
     * @param bool        $aclTops Whether some top folders are not readable
     */
    protected function CTE_FOLDERS_ARCHIVE(?string $acl, bool $aclTops): string
    {
        $CLS_READABLE = null !== $acl
            ? 'COALESCE(CASE WHEN acl.rmask = 1 THEN acl.rperm END, c.readable)'
            : '1';
        $ACL_JOIN = null !== $acl
            ? 'LEFT JOIN *PREFIX*cte_acl acl ON (acl.fileid = f.fileid)'
            : '';

        $cte = "*PREFIX*cte_folders(fileid, readable) AS (
            SELECT
                cfa.fileid, MAX(cfa.readable)
            FROM
                *PREFIX*cte_folders_all cfa
            WHERE
                cfa.name = '.archive'
            GROUP BY
                cfa.fileid
            UNION ALL
            SELECT
                f.fileid, ({$CLS_READABLE})
            FROM
                *PREFIX*filecache f
            INNER JOIN *PREFIX*cte_folders c
                ON (
                    f.parent = c.fileid AND
                    f.mimetype = (
                        SELECT MAX(id) FROM *PREFIX*mimetypes
                        WHERE mimetype = 'httpd/unix-directory'
                    )
                )
            {$ACL_JOIN}
        )";

        return self::bundleCTEs([
            ...(null !== $acl ? [$this->CTE_ACL($acl)] : []),
            $this->CTE_FOLDERS_ALL(true, 0, $acl, $aclTops),
            $cte,
        ]);
    }

    /**
     * CTE to walk up the parents of a single file.
     * :cteFileId - The fileid to start from (depth 0).
     *
     * Each row is one ancestor (depth 0 is the file itself).
     * Recursion stops at parent -1 or after 48 steps.
     */
    protected function CTE_FILE_PARENTS(): string
    {
        $cte = '*PREFIX*cte_file_parents(fileid, parent, name, depth) AS (
            SELECT f.fileid, f.parent, f.name, 0
            FROM *PREFIX*filecache f
            WHERE f.fileid = :cteFileId

            UNION ALL

            SELECT f.fileid, f.parent, f.name, c.depth + 1
            FROM *PREFIX*filecache f
            INNER JOIN *PREFIX*cte_file_parents c
                ON (f.fileid = c.parent)
            WHERE (
                (c.parent <> -1) AND
                (c.depth < 48)
            )
        )';

        return self::bundleCTEs([$cte]);
    }

    /**
     * @param string[] $ctes The CTEs to bundle
     */
    protected static function bundleCTEs(array $ctes): string
    {
        return 'WITH RECURSIVE '.implode(',', $ctes);
    }
}
