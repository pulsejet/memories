<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

final class CTEParams
{
    private const TOP_FOLDER_IDS = 'topFolderIds';
    private const FOLDERS_ARCHIVE = 'cteFoldersArchive';
    private const INCLUDE_HIDDEN = 'cteIncludeHidden';

    private const FOLDER_NAME_BLOCKLIST = 'fnBlocklist';
    private const FOLDER_NAME_BLOCKLIST_COUNT = 'fnBlocklistCount';
    private const FOLDER_NAME_BLOCKLIST_ESCAPE = 'fnBlocklistEscape';
    private const FOLDER_NAME_BLOCKLIST_ESCAPE_CHAR = '\\';

    public static function setTopFolderIds(IQueryBuilder &$query, array $ids): void
    {
        $query->setParameter(self::TOP_FOLDER_IDS, array_values($ids), IQueryBuilder::PARAM_INT_ARRAY);
    }

    public static function setFoldersArchive(IQueryBuilder &$query, bool $enabled = true): void
    {
        if ($enabled) {
            $query->setParameter(self::FOLDERS_ARCHIVE, true, IQueryBuilder::PARAM_BOOL);
        }
    }

    public static function isFoldersArchive(IQueryBuilder $query): bool
    {
        return \array_key_exists(self::FOLDERS_ARCHIVE, $query->getParameters());
    }

    public static function setIncludeHidden(IQueryBuilder &$query, bool $enabled = true): void
    {
        if ($enabled) {
            $query->setParameter(self::INCLUDE_HIDDEN, true, IQueryBuilder::PARAM_BOOL);
        }
    }

    public static function isIncludeHidden(IQueryBuilder $query): bool
    {
        return \array_key_exists(self::INCLUDE_HIDDEN, $query->getParameters());
    }

    /** @param string[] $patterns */
    public static function setFolderNameBlocklist(IQueryBuilder &$query, array $patterns): void
    {
        $patterns = array_values($patterns);

        if ([] !== $patterns) {
            $query->setParameter(
                self::FOLDER_NAME_BLOCKLIST_COUNT,
                \count($patterns),
                IQueryBuilder::PARAM_INT,
            );
            $query->setParameter(
                self::FOLDER_NAME_BLOCKLIST_ESCAPE,
                self::FOLDER_NAME_BLOCKLIST_ESCAPE_CHAR,
                IQueryBuilder::PARAM_STR,
            );
        }

        foreach ($patterns as $i => $pattern) {
            $query->setParameter(
                self::FOLDER_NAME_BLOCKLIST."{$i}",
                $pattern,
                IQueryBuilder::PARAM_STR,
            );
        }
    }

    public static function getFolderNameBlocklistCount(IQueryBuilder $query): int
    {
        return (int) ($query->getParameters()[self::FOLDER_NAME_BLOCKLIST_COUNT] ?? 0);
    }
}
