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
        if (str_contains($sql, 'cte_folders')) {
            $CTE_SQL = CTEParams::isFoldersArchive($query)
                ? $this->CTE_FOLDERS_ARCHIVE()
                : $this->CTE_FOLDERS(
                    CTEParams::isIncludeHidden($query),
                    CTEParams::getFolderNameBlocklistCount($query),
                );
            $sql = $CTE_SQL.' '.$sql;
        } elseif (str_contains($sql, 'cte_file_parents')) {
            $CTE_SQL = $this->CTE_FILE_PARENTS();
            $sql = $CTE_SQL.' '.$sql;
        }

        return $this->connection->executeQuery($sql, $params, $types);
    }

    /**
     * @param int    $count number of bound :fnBlocklistN patterns
     * @param string $alias table alias holding the folder name column
     */
    public function folderNotBlocklistedClause(int $count, string $alias): string
    {
        if (0 === $count) {
            return '(1 = 1)';
        }

        $parts = [];
        for ($i = 0; $i < $count; ++$i) {
            $parts[] = "({$alias}.name NOT LIKE :fnBlocklist{$i} ESCAPE :fnBlocklistEscape)";
        }

        return '('.implode(' AND ', $parts).')';
    }

    /**
     * CTE to get all files recursively in the given top folders
     * :topFolderIds - The top folders to get files from.
     * :fnBlocklistN - Folder name LIKE patterns to prune.
     *
     * @param bool $hidden   Whether to include files in hidden folders
     *                       If the top folder is hidden, the files in it will still be returned
     *                       Hidden files are marked as such in the "hidden" field
     * @param int  $fnBlockN Number of folder blocklist patterns
     */
    protected function CTE_FOLDERS_ALL(bool $hidden, int $fnBlockN): string
    {
        $provider = $this->connection->getDatabaseProvider();

        // Filter out folder MIME types
        $FOLDER_MIME_QUERY = "SELECT MAX(id) FROM *PREFIX*mimetypes WHERE mimetype = 'httpd/unix-directory'";

        // Select 1 if there is a file in the folder with the specified name
        $SEL_FILE = static fn (string $name): string => "SELECT 1 FROM *PREFIX*filecache f2
            WHERE (f2.parent = f.fileid)
            AND (f2.name = '{$name}')";

        // Check for nomedia and nomemories files
        // Two separate subqueries can actually be faster here (up to 10x on MariaDB)
        $SEL_NOMEDIA = $SEL_FILE('.nomedia');
        $SEL_NOMEMORIES = $SEL_FILE('.nomemories');
        $CLS_NOMEDIA = "NOT EXISTS ({$SEL_NOMEDIA}) AND NOT EXISTS ({$SEL_NOMEMORIES})";

        // Whether to filter out hidden folders
        $CLS_HIDDEN_JOIN = $hidden ? '1 = 1' : "f.name NOT LIKE '.%'";

        // Blocklisted folder names prune the whole subtree (pfx AND)
        $CLS_BLOCKLIST = $this->folderNotBlocklistedClause($fnBlockN, 'f');

        // On MySQL or MariaDB, provide the hint to use the index
        // The index is not used sometimes since the table is unbalanced
        // and fs_parent is used instead
        $UNION_INDEX_HINT = IDBConnection::PLATFORM_MYSQL === $provider
            ? 'USE INDEX (memories_parent_mimetype)'
            : '';

        return
        "*PREFIX*cte_folders_all(fileid, name, hidden) AS (
            SELECT f.fileid, f.name,
                (0) AS hidden
            FROM *PREFIX*filecache f
            WHERE (
                (f.fileid IN (:topFolderIds)) AND
                ({$CLS_NOMEDIA}) AND
                ({$CLS_BLOCKLIST})
            )

            UNION ALL

            SELECT f.fileid, f.name,
                (CASE WHEN c.hidden = 1 OR f.name LIKE '.%' THEN 1 ELSE 0 END) AS hidden
            FROM *PREFIX*filecache f
            {$UNION_INDEX_HINT}
            INNER JOIN *PREFIX*cte_folders_all c
                ON (
                    f.parent = c.fileid AND
                    f.mimetype = ({$FOLDER_MIME_QUERY}) AND
                    ({$CLS_HIDDEN_JOIN})
                )
            WHERE (
                ({$CLS_NOMEDIA}) AND
                ({$CLS_BLOCKLIST})
            )
        )";
    }

    /**
     * CTE to get all folders recursively in the given top folders.
     *
     * @param bool $hidden   Whether to include files in hidden folders
     * @param int  $fnBlockN Number of folder blocklist patterns
     */
    protected function CTE_FOLDERS(bool $hidden, int $fnBlockN): string
    {
        $CLS_HIDDEN = $hidden ? 'MIN(hidden)' : '0';

        $cte = "*PREFIX*cte_folders AS (
            SELECT
                fileid, ({$CLS_HIDDEN}) AS hidden
            FROM
                *PREFIX*cte_folders_all
            GROUP BY
                fileid
        )";

        return self::bundleCTEs([$this->CTE_FOLDERS_ALL($hidden, $fnBlockN), $cte]);
    }

    /**
     * CTE to get all archive folders recursively in the given top folders.
     */
    protected function CTE_FOLDERS_ARCHIVE(): string
    {
        $cte = "*PREFIX*cte_folders(fileid) AS (
            SELECT
                cfa.fileid
            FROM
                *PREFIX*cte_folders_all cfa
            WHERE
                cfa.name = '.archive'
            GROUP BY
                cfa.fileid
            UNION ALL
            SELECT
                f.fileid
            FROM
                *PREFIX*filecache f
            INNER JOIN *PREFIX*cte_folders c
                ON (f.parent = c.fileid)
        )";

        return self::bundleCTEs([$this->CTE_FOLDERS_ALL(true, 0), $cte]);
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
