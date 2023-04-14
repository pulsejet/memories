<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

trait TimelineQueryCTE
{
    protected static function CTE_FOLDERS_ALL(bool $notArchive): string
    {
        // Whether to filter out the archive folder
        $CLS_ARCHIVE_JOIN = $notArchive ? "f.name <> '.archive'" : '1 = 1';

        // Filter out folder MIME types
        $CLS_MIME_FOLDER = "f.mimetype = (SELECT `id` FROM `*PREFIX*mimetypes` WHERE `mimetype` = 'httpd/unix-directory')";

        // Select filecache as f
        $BASE_QUERY = 'SELECT f.fileid, f.name FROM *PREFIX*filecache f';

        // From top folders
        $CLS_TOP_FOLDER = 'f.fileid IN (:topFolderIds)';

        // Select 1 if there is a .nomedia file in the folder
        $SEL_NOMEDIA = "SELECT 1 FROM *PREFIX*filecache f2 WHERE f2.parent = f.fileid AND f2.name = '.nomedia'";

        // Check no nomedia file exists in the folder
        $CLS_NOMEDIA = "NOT EXISTS ({$SEL_NOMEDIA})";

        return
        "*PREFIX*cte_folders_all(fileid, name) AS (
            {$BASE_QUERY}
            WHERE (
                {$CLS_TOP_FOLDER} AND
                {$CLS_NOMEDIA}
            )

            UNION ALL

            {$BASE_QUERY}
            INNER JOIN *PREFIX*cte_folders_all c
                ON (
                    f.parent = c.fileid AND
                    {$CLS_MIME_FOLDER} AND
                    {$CLS_ARCHIVE_JOIN}
                )
            WHERE (
                {$CLS_NOMEDIA}
            )
        )";
    }

    /** CTE to get all folders recursively in the given top folders excluding archive */
    protected static function CTE_FOLDERS(): string
    {
        $cte = '*PREFIX*cte_folders AS (
            SELECT
                fileid
            FROM
                *PREFIX*cte_folders_all
            GROUP BY
                fileid
        )';

        return self::bundleCTEs([self::CTE_FOLDERS_ALL(true), $cte]);
    }

    /** CTE to get all archive folders recursively in the given top folders */
    protected static function CTE_FOLDERS_ARCHIVE(): string
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

        return self::bundleCTEs([self::CTE_FOLDERS_ALL(false), $cte]);
    }

    protected static function bundleCTEs(array $ctes): string
    {
        return 'WITH RECURSIVE '.implode(',', $ctes);
    }
}
