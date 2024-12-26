<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\IDBConnection;

trait TimelineQueryCTE
{
    protected IDBConnection $connection;

    /**
     * CTE to get all files recursively in the given top folders
     * :topFolderIds - The top folders to get files from.
     *
     * @param bool $hidden Whether to include files in hidden folders
     *                     If the top folder is hidden, the files in it will still be returned
     *                     Hidden files are marked as such in the "hidden" field
     */
    protected function CTE_FOLDERS_ALL(bool $hidden): string
    {
        $platform = $this->connection->getDatabasePlatform();

        // Filter out folder MIME types
        $FOLDER_MIME_QUERY = "SELECT MAX(id) FROM *PREFIX*mimetypes WHERE mimetype = 'httpd/unix-directory'";

        // Select 1 if there is a file in the folder with the specified name
        $SEL_FILE = static fn (string $name): string => "SELECT 1 FROM *PREFIX*filecache f2
            WHERE (f2.parent = f.fileid)
            AND (f2.name = '{$name}')";

        // Check for nomedia and nomemories files
        // Two separate subqueries can actually be faster here (upto 10x on MariaDB)
        $SEL_NOMEDIA = $SEL_FILE('.nomedia');
        $SEL_NOMEMORIES = $SEL_FILE('.nomemories');
        $CLS_NOMEDIA = "NOT EXISTS ({$SEL_NOMEDIA}) AND NOT EXISTS ({$SEL_NOMEMORIES})";

        // Whether to filter out hidden folders
        $CLS_HIDDEN_JOIN = $hidden ? '1 = 1' : "f.name NOT LIKE '.%'";

        // On MySQL or MariaDB, provide the hint to use the index
        // The index is not used sometimes since the table is unbalanced
        // and fs_parent is used instead
        $UNION_INDEX_HINT = preg_match('/mysql|mariadb/i', $platform::class)
            ? 'USE INDEX (memories_parent_mimetype)'
            : '';

        return
        "*PREFIX*cte_folders_all(fileid, name, hidden) AS (
            SELECT f.fileid, f.name,
                (0) AS hidden
            FROM *PREFIX*filecache f
            WHERE (
                f.fileid IN (:topFolderIds) AND
                {$CLS_NOMEDIA}
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
                {$CLS_NOMEDIA}
            )
        )";
    }

    /**
     * CTE to get all folders recursively in the given top folders.
     *
     * @param bool $hidden Whether to include files in hidden folders
     */
    protected function CTE_FOLDERS(bool $hidden): string
    {
        $CLS_HIDDEN = $hidden ? 'MIN(hidden)' : '0';

        return "*PREFIX*cte_folders AS (
            SELECT
                fileid, ({$CLS_HIDDEN}) AS hidden
            FROM
                *PREFIX*cte_folders_all
            GROUP BY
                fileid
        )";
    }

    /**
     * CTE to get all archive folders recursively in the given top folders.
     */
    protected function CTE_FOLDERS_ARCHIVE(): string
    {
        return "*PREFIX*cte_folders(fileid) AS (
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
    }

    protected function CTE_SHARED_ALBUM_FILES(): string
    {
        // Detect database type
        $platform = $this->connection->getDatabasePlatform()::class;
        $DISTINCT_ON = '';
        $GROUP_BY = '';

        if (preg_match('/mysql|mariadb/i', $platform)) {
            $GROUP_BY = 'GROUP BY paf.file_id';
        } elseif (preg_match('/postgres/i', $platform)) {
            $DISTINCT_ON = 'DISTINCT ON (paf.file_id)';
        } else {
            throw new \Exception("Unsupported database detected: {$platform}");
        }

        return "*PREFIX*cte_shared_album_files(fileid, album_path) AS (
            SELECT {$DISTINCT_ON}
                paf.file_id AS fileid,
                CONCAT(pa.user, '/', pa.name) AS album_path
            FROM
                *PREFIX*photos_albums_files paf
            INNER JOIN
                *PREFIX*photos_albums pa ON pa.album_id = paf.album_id
            INNER JOIN
                *PREFIX*photos_albums_collabs pac ON pac.album_id = paf.album_id
            WHERE
                pac.collaborator_id = :uid OR pa.user = :uid
            {$GROUP_BY}
        )";
    }

    protected function CTE_SHARED_ALBUM_FILES_EMPTY(): string
    {
        return '*PREFIX*cte_shared_album_files(fileid, album_path) AS (
            SELECT
                0 AS fileid,
                NULL AS album_path
        )';
    }

    /**
     * @param string[] $ctes The CTEs to bundle
     */
    protected static function bundleCTEs(array $ctes): string
    {
        return 'WITH RECURSIVE '.implode(',', $ctes);
    }
}
