<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

trait TimelineQueryCTE
{
    /**
     * CTE to get all files recursively in the given top folders
     * :topFolderIds - The top folders to get files from
     *
     * @param bool $noHidden Whether to filter out files in hidden folders
     * If the top folder is hidden, the files in it will still be returned
     */
    protected static function CTE_FOLDERS_ALL(bool $noHidden): string
    {
        // Whether to filter out the archive folder
        $CLS_HIDDEN_JOIN = $noHidden ? "f.name NOT LIKE '.%'" : '1 = 1';

        // Filter out folder MIME types
        $CLS_MIME_FOLDER = "f.mimetype = (SELECT `id` FROM `*PREFIX*mimetypes` WHERE `mimetype` = 'httpd/unix-directory')";

        // Select filecache as f
        $BASE_QUERY = 'SELECT f.fileid, f.name FROM *PREFIX*filecache f';

        // From top folders
        $CLS_TOP_FOLDER = 'f.fileid IN (:topFolderIds)';

        // Select 1 if there is a .nomedia file in the folder
        $SEL_NOMEDIA = "SELECT 1 FROM *PREFIX*filecache f2
            WHERE (f2.parent = f.fileid)
            AND (f2.name = '.nomedia' OR f2.name = '.nomemories')";

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
                    {$CLS_HIDDEN_JOIN}
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
