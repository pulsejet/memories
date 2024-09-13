<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;

class SQL
{
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
    public static function subquery(IQueryBuilder &$query, IQueryBuilder &$subquery): IQueryFunction
    {
        return $query->createFunction("({$subquery->getSQL()})");
    }

    /**
     * Create an EXISTS expression.
     *
     * @param IQueryBuilder        $query  The query to create the function on
     * @param IQueryBuilder|string $clause The clause to check for existence
     */
    public static function exists(IQueryBuilder &$query, IQueryBuilder|string &$clause): IQueryFunction
    {
        if ($clause instanceof IQueryBuilder) {
            $clause = $clause->getSQL();
        }

        return $query->createFunction("EXISTS ({$clause})");
    }

    /**
     * Create a NOT EXISTS expression.
     *
     * @param IQueryBuilder        $query  The query to create the function on
     * @param IQueryBuilder|string $clause The clause to check for existence
     */
    public static function notExists(IQueryBuilder &$query, IQueryBuilder|string &$clause): IQueryFunction
    {
        if ($clause instanceof IQueryBuilder) {
            $clause = $clause->getSQL();
        }

        return $query->createFunction("NOT EXISTS ({$clause})");
    }

    /**
     * Create a DISTINCT expression.
     *
     * @param IQueryBuilder $query The query to create the function on
     * @param string        $field The field to select distinct values from
     */
    public static function distinct(IQueryBuilder &$query, string $field): IQueryFunction
    {
        return $query->createFunction("DISTINCT {$field}");
    }

    /**
     * Create a AVG expression.
     *
     * @param IQueryBuilder $query The query to create the function on
     * @param string        $field The field to average
     */
    public static function average(IQueryBuilder &$query, string $field): IQueryFunction
    {
        return $query->createFunction("AVG({$field})");
    }
}
