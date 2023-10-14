<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;

class TimelineQuery
{
    use TimelineQueryDays;
    use TimelineQueryFilters;
    use TimelineQueryFolders;
    use TimelineQueryLivePhoto;
    use TimelineQueryMap;
    use TimelineQueryNativeX;
    use TimelineQuerySingleItem;

    public const TIMELINE_SELECT = [
        'm.datetaken', 'm.dayid',
        'm.w', 'm.h', 'm.liveid',
        'm.isvideo', 'm.video_duration',
        'f.etag', 'f.name AS basename',
        'mimetypes.mimetype',
    ];

    protected IDBConnection $connection;
    protected IRequest $request;
    protected ?TimelineRoot $_root = null; // cache
    protected bool $_rootEmptyAllowed = false;

    public function __construct(IDBConnection $connection, IRequest $request)
    {
        $this->connection = $connection;
        $this->request = $request;
    }

    public function allowEmptyRoot(bool $value = true): void
    {
        $this->_rootEmptyAllowed = $value;
    }

    public function getBuilder(): IQueryBuilder
    {
        return $this->connection->getQueryBuilder();
    }

    /**
     * @return never
     */
    public static function debugQuery(IQueryBuilder &$query, string $sql = '')
    {
        // Print the query and exit
        $sql = empty($sql) ? $query->getSQL() : $sql;
        $sql = str_replace('*PREFIX*', 'oc_', $sql);
        $sql = self::replaceQueryParams($query, $sql);
        echo "{$sql}";

        exit; // only for debugging, so this is okay
    }

    public static function replaceQueryParams(IQueryBuilder &$query, string $sql): string
    {
        $params = $query->getParameters();
        $platform = $query->getConnection()->getDatabasePlatform();
        foreach ($params as $key => $value) {
            if (\is_array($value)) {
                $value = implode(',', array_map(static fn ($v) => $platform->quoteStringLiteral($v), $value));
            } elseif (\is_bool($value)) {
                $value = $platform->quoteStringLiteral($value ? '1' : '0');
            } elseif (null === $value) {
                $value = $platform->quoteStringLiteral('NULL');
            }

            $sql = str_replace(':'.$key, $value, $sql);
        }

        return $sql;
    }
}
