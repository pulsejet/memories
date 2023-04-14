<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

trait TimelineQueryCTE
{
    protected static function CTE_FOLDERS_ALL(bool $notArchive): string
    {
        $extraJoinOn = $notArchive ? "AND f.name <> '.archive'" : '';

        return
        "*PREFIX*cte_folders_all(fileid, name) AS (
            SELECT
                f.fileid,
                f.name
            FROM
                *PREFIX*filecache f
            WHERE
                f.fileid IN (:topFolderIds)
            UNION ALL
            SELECT
                f.fileid,
                f.name
            FROM
                *PREFIX*filecache f
            INNER JOIN *PREFIX*cte_folders_all c
                ON (f.parent = c.fileid
                    AND f.mimetype = (SELECT `id` FROM `*PREFIX*mimetypes` WHERE `mimetype` = 'httpd/unix-directory')
                    {$extraJoinOn}
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
        $cte = '*PREFIX*cte_folders(fileid) AS (
            SELECT
                cfa.fileid
            FROM
                *PREFIX*cte_folders_all cfa
            WHERE
                cfa.name = \'.archive\'
            GROUP BY
                cfa.fileid
            UNION ALL
            SELECT
                f.fileid
            FROM
                *PREFIX*filecache f
            INNER JOIN *PREFIX*cte_folders c
                ON (f.parent = c.fileid)
        )';

        return self::bundleCTEs([self::CTE_FOLDERS_ALL(false), $cte]);
    }

    protected static function bundleCTEs(array $ctes): string
    {
        return 'WITH RECURSIVE ' . implode(',', $ctes);
    }
}
