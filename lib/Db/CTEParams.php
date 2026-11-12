<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

final class CTEParams
{
    private const TOP_FOLDER_IDS = 'topFolderIds';
    private const FOLDERS_ARCHIVE = 'cteFoldersArchive';
    private const INCLUDE_HIDDEN = 'cteIncludeHidden';
    private const FILE_ID = 'cteFileId';
    private const ACL_MAPPINGS = 'cteAcl';
    private const ACL_MAPPING_TYPES = ['user', 'group', 'circle'];
    private const ACL_DENIED_TOPS = 'cteAclDeniedTops';

    private const BLOCKLIST = 'fnBlocklist';
    private const BLOCKLIST_COUNT = 'fnBlocklistCount';
    private const BLOCKLIST_ESCAPE = 'fnBlocklistEscape';
    private const BLOCKLIST_ESCAPE_CHAR = '\\';

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

    public static function setFileId(IQueryBuilder &$query, int $id): void
    {
        $query->setParameter(self::FILE_ID, $id, IQueryBuilder::PARAM_INT);
    }

    /**
     * Apply the group folder ACL rules matching these mappings.
     *
     * @param array<string, string[]> $mappings   mapping ids by type (user, group, circle)
     * @param int[]                   $deniedTops top folders that are not readable themselves
     */
    public static function setAcl(IQueryBuilder &$query, array $mappings, array $deniedTops): void
    {
        foreach (self::ACL_MAPPING_TYPES as $type) {
            $ids = array_values($mappings[$type] ?? []);
            if ([] !== $ids) {
                $query->setParameter(self::ACL_MAPPINGS.ucfirst($type), $ids, IQueryBuilder::PARAM_STR_ARRAY);
            }
        }

        if ([] !== $deniedTops && self::hasAcl($query)) {
            $query->setParameter(self::ACL_DENIED_TOPS, array_values($deniedTops), IQueryBuilder::PARAM_INT_ARRAY);
        }
    }

    public static function hasAcl(IQueryBuilder $query): bool
    {
        foreach (self::ACL_MAPPING_TYPES as $type) {
            if (\array_key_exists(self::ACL_MAPPINGS.ucfirst($type), $query->getParameters())) {
                return true;
            }
        }

        return false;
    }

    public static function hasAclDeniedTops(IQueryBuilder $query): bool
    {
        return \array_key_exists(self::ACL_DENIED_TOPS, $query->getParameters());
    }

    /**
     * SQL clause matching the ACL rules in alias that apply to the user.
     */
    public static function aclMappingClause(IQueryBuilder $query, string $alias): string
    {
        $parts = [];
        foreach (self::ACL_MAPPING_TYPES as $type) {
            $param = self::ACL_MAPPINGS.ucfirst($type);
            if (\array_key_exists($param, $query->getParameters())) {
                $parts[] = "({$alias}.mapping_type = '{$type}' AND {$alias}.mapping_id IN (:{$param}))";
            }
        }

        return implode(' OR ', $parts);
    }

    /**
     * SQL clause matching files the ACL rules let the user read; needs cte_acl.
     * A rule on the file itself wins over its folder.
     *
     * @param string $folderReadable expression that is 1 if the folder of the file is readable
     * @param string $fileid         column with the fileid
     */
    public static function aclReadableClause(string $folderReadable, string $fileid): string
    {
        $rule = 'SELECT acl_r.fileid FROM *PREFIX*cte_acl acl_r WHERE acl_r.rmask = 1 AND acl_r.rperm = ';

        return "(({$folderReadable} = 1 AND {$fileid} NOT IN ({$rule}0)) OR {$fileid} IN ({$rule}1))";
    }

    /** @param string[] $patterns */
    public static function setBlocklist(IQueryBuilder &$query, array $patterns): void
    {
        $patterns = array_values($patterns);

        if ([] !== $patterns) {
            $query->setParameter(
                self::BLOCKLIST_COUNT,
                \count($patterns),
                IQueryBuilder::PARAM_INT,
            );
            $query->setParameter(
                self::BLOCKLIST_ESCAPE,
                self::BLOCKLIST_ESCAPE_CHAR,
                IQueryBuilder::PARAM_STR,
            );
        }

        foreach ($patterns as $i => $pattern) {
            $query->setParameter(
                self::BLOCKLIST."{$i}",
                $pattern,
                IQueryBuilder::PARAM_STR,
            );
        }
    }

    public static function getBlocklistCount(IQueryBuilder $query): int
    {
        return (int) ($query->getParameters()[self::BLOCKLIST_COUNT] ?? 0);
    }
}
