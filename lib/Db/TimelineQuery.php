<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
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

    protected ?TimelineRoot $_root = null; // cache
    protected bool $_rootEmptyAllowed = false;

    public function __construct(
        protected IDBConnection $connection,
        protected IRequest $request,
    ) {}

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
            } else {
                $value = $platform->quoteStringLiteral((string) $value);
            }

            $sql = str_replace(':'.$key, $value, $sql);
        }

        return $sql;
    }

    /**
     * Materialize a query as a subquery and select everything from it.
     * This is very useful for optimization.
     *
     * @param IQueryBuilder $query The query to materialize
     * @param string        $alias The alias to use for the subquery
     */
    public static function materialize(IQueryBuilder $query, string $alias): IQueryBuilder
    {
        // Create new query and copy over parameters (and types)
        $outer = $query->getConnection()->getQueryBuilder();
        $outer->setParameters($query->getParameters(), $query->getParameterTypes());

        // Create the subquery function for selecting from it
        $outer->select("{$alias}.*")->from(self::subquery($outer, $query), $alias);

        return $outer;
    }

    /**
     * Create a subquery function.
     *
     * @param IQueryBuilder $query    The query to create the function on
     * @param IQueryBuilder $subquery The subquery to use
     */
    public static function subquery(IQueryBuilder $query, IQueryBuilder $subquery): IQueryFunction
    {
        return $query->createFunction("({$subquery->getSQL()})");
    }

    /**
     * Add etag for a field in a query.
     *
     * @param IQueryBuilder $query The query to add the etag to
     * @param string        $field The field to add the etag for
     * @param string        $alias The alias to use for the etag
     */
    public static function selectEtag(IQueryBuilder &$query, string $field, string $alias): void
    {
        $sub = $query->getConnection()->getQueryBuilder();
        $sub->select('etag')
            ->from('filecache', 'etag_f')
            ->where($sub->expr()->eq('etag_f.fileid', $field))
            ->setMaxResults(1)
        ;
        $query->selectAlias(self::subquery($query, $sub), $alias);
    }
}
