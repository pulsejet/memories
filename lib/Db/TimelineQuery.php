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
    use TimelineQuerySingleItem;

    public const TIMELINE_SELECT = [
        'm.isvideo', 'm.video_duration', 'm.datetaken', 'm.dayid', 'm.w', 'm.h', 'm.liveid',
        'f.etag', 'f.name AS basename', 'mimetypes.mimetype',
    ];

    protected IDBConnection $connection;
    protected IRequest $request;
    protected ?TimelineRoot $_root = null;
    protected bool $_rootEmptyAllowed = false;

    public function __construct(IDBConnection $connection, IRequest $request)
    {
        $this->connection = $connection;
        $this->request = $request;
    }

    public function root(?TimelineRoot $override = null): TimelineRoot
    {
        if (null !== $override) {
            $this->_root = $override;
        }

        if (null === $this->_root) {
            $this->_root = new TimelineRoot();
            $this->_root->populate();
        }

        if (!$this->_rootEmptyAllowed && $this->_root->isEmpty()) {
            throw new \Exception('No valid root folder found (.nomedia?)');
        }

        return $this->_root;
    }

    public function allowEmptyRoot(bool $value = true)
    {
        $this->_rootEmptyAllowed = $value;
    }

    public function getBuilder()
    {
        return $this->connection->getQueryBuilder();
    }

    public static function debugQuery(IQueryBuilder &$query, string $sql = '')
    {
        // Print the query and exit
        $sql = empty($sql) ? $query->getSQL() : $sql;
        $sql = str_replace('*PREFIX*', 'oc_', $sql);
        $sql = self::replaceQueryParams($query, $sql);
        echo "{$sql}";

        exit; // only for debugging, so this is okay
    }

    public static function replaceQueryParams(IQueryBuilder &$query, string $sql)
    {
        $params = $query->getParameters();
        foreach ($params as $key => $value) {
            if (\is_array($value)) {
                $value = implode(',', $value);
            } elseif (\is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (null === $value) {
                $value = 'NULL';
            }

            $value = $query->getConnection()->getDatabasePlatform()->quoteStringLiteral($value);
            $sql = str_replace(':'.$key, $value, $sql);
        }

        return $sql;
    }
}
