<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IConfig;
use OCP\IDBConnection;

final class SQL
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
        $conn = $query->getConnection();

        foreach ($query->getParameters() as $key => $value) {
            if (\is_array($value)) {
                $value = implode(',', array_map(static fn ($v): string => $conn->quote($v), $value));
            } elseif (\is_bool($value)) {
                $value = $conn->quote($value ? '1' : '0');
            } elseif (null === $value) {
                $value = $conn->quote('NULL');
            } else {
                $value = $conn->quote((string) $value);
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

    /**
     * TRUNCATE a table (remove all rows and reset auto-increment).
     * This wrapper should be removed when support for Nextcloud <32 is dropped.
     *
     * @param IDBConnection $connection The database connection
     * @param string        $table      The table to truncate
     * @param bool          $cascade    Whether to cascade the truncate operation
     */
    public static function truncate(IDBConnection &$connection, string $table, bool $cascade): void
    {
        // getDatabasePlatform is deprecated on Nextcloud 32
        if (method_exists($connection, 'truncateTable')) {
            $connection->truncateTable($table, $cascade);
        } else {
            /** @psalm-suppress DeprecatedMethod */
            $sql = $connection->getDatabasePlatform()->getTruncateTableSQL('*PREFIX*'.$table, $cascade);
            $connection->executeStatement($sql);
        }
    }

    /**
     * Escape a string value for MySQL LOAD DATA TSV format.
     */
    public static function escapeTsv(string $str): string
    {
        return str_replace(['\\', "\t", "\n", "\r", "\0"], ['\\\\', '\t', '\n', '\r', ''], $str);
    }

    /**
     * Get a dedicated PDO instance for MySQL with local_infile enabled.
     *
     * Why a separate PDO connection is necessary:
     * 1. PHP's PDO MySQL driver (ext-pdo_mysql) requires `\PDO::MYSQL_ATTR_LOCAL_INFILE => true`
     *    to be specified at connection construction time. Calling `setAttribute` on an existing
     *    connection does not renegotiate the MySQL client capability flags (CLIENT_LOCAL_FILES)
     *    agreed upon during the initial connection handshake, resulting in MySQL error 2068:
     *    "LOAD DATA LOCAL INFILE is forbidden".
     * 2. Nextcloud's shared database connection pool initializes connections without LOCAL INFILE
     *    support for general database operations.
     * 3. Non-local `LOAD DATA INFILE` requires the global `FILE` privilege on the database server
     *    (which typical database users do not possess) and requires files to exist on the database
     *    server's local filesystem (which breaks when MySQL runs in a separate container/host).
     *
     * Note: IDBConnection is an interface that does not expose getInner() in its contract.
     * We cast/verify the underlying ConnectionAdapter to retrieve active connection parameters.
     *
     * @psalm-suppress InternalMethod
     */
    public static function getMysqlPdo(IDBConnection $connection, IConfig $config): \PDO
    {
        if (!$connection instanceof \OC\DB\ConnectionAdapter) {
            throw new \Exception('Expected database connection to be an instance of ConnectionAdapter');
        }

        $inner = $connection->getInner();
        $params = $inner->getParams();

        /** @var string $user */
        $user = $params['user'] ?? $config->getSystemValue('dbuser', '');

        /** @var string $password */
        $password = $params['password'] ?? $config->getSystemValue('dbpassword', '');

        /** @var string $dbname */
        $dbname = $params['dbname'] ?? $config->getSystemValue('dbname', '');

        /** @var string $host */
        $host = $params['host'] ?? $config->getSystemValue('dbhost', 'localhost');

        /** @var int $port */
        $port = $params['port'] ?? $config->getSystemValueInt('dbport', 0);

        /** @var string $socket */
        $socket = $params['unix_socket'] ?? $config->getSystemValueString('dbsocket', '');

        // Nextcloud can specify dbhost as "host:port" or "host:socket"
        if (empty($socket) && 0 === $port && preg_match('/^(.*):([^\]:]+)$/', $host, $matches)) {
            $host = $matches[1];
            if (is_numeric($matches[2])) {
                $port = (int) $matches[2];
            } else {
                $socket = $matches[2];
            }
        }

        if (!empty($socket)) {
            $dsn = "mysql:unix_socket={$socket};dbname={$dbname};charset=utf8mb4";
        } elseif ($port > 0) {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        } else {
            $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
        }

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ];
        if (\defined('\PDO::MYSQL_ATTR_LOCAL_INFILE')) {
            $options[\PDO::MYSQL_ATTR_LOCAL_INFILE] = true;
        }

        return new \PDO($dsn, $user, $password, $options);
    }

    /**
     * Copy data from a file into a MySQL table using LOAD DATA LOCAL INFILE.
     */
    public static function mysqlCopyFromFile(\PDO $pdo, string $table, string $file, string $fields = '', string $setClause = ''): void
    {
        $sql = 'LOAD DATA LOCAL INFILE '.$pdo->quote($file).' INTO TABLE '.$table;
        if ('' !== $fields) {
            $sql .= " ({$fields})";
        }
        if ('' !== $setClause) {
            $sql .= " SET {$setClause}";
        }

        $res = $pdo->exec($sql);
        if (false === $res) {
            throw new \Exception("Failed to copy data from {$file} into table {$table}");
        }
    }

    /**
     * Get a PDO instance for PostgreSQL.
     *
     * @psalm-suppress InternalMethod
     */
    public static function getPgsqlPdo(IDBConnection $connection, IConfig $config): \PDO
    {
        if (!$connection instanceof \OC\DB\ConnectionAdapter) {
            throw new \Exception('Expected database connection to be an instance of ConnectionAdapter');
        }

        $inner = $connection->getInner();
        $native = $inner->getNativeConnection();
        if ($native instanceof \PDO) {
            return $native;
        }

        $params = $inner->getParams();

        /** @var string $user */
        $user = $params['user'] ?? $config->getSystemValue('dbuser', '');

        /** @var string $password */
        $password = $params['password'] ?? $config->getSystemValue('dbpassword', '');

        /** @var string $dbname */
        $dbname = $params['dbname'] ?? $config->getSystemValue('dbname', '');

        /** @var string $host */
        $host = $params['host'] ?? $config->getSystemValue('dbhost', 'localhost');

        /** @var int $port */
        $port = $params['port'] ?? $config->getSystemValueInt('dbport', 5432);

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";

        return new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * Copy data from a file into a PostgreSQL table using PDO.
     */
    public static function pgsqlCopyFromFile(\PDO $pdo, string $table, string $file, string $fields = ''): void
    {
        /** @psalm-suppress UndefinedMethod */
        if (method_exists($pdo, 'copyFromFile')) {
            /** @var mixed $res */
            $res = '' !== $fields
                ? $pdo->copyFromFile($table, $file, "\t", '\\\N', $fields)
                : $pdo->copyFromFile($table, $file, "\t", '\\\N');
        } elseif (method_exists($pdo, 'pgsqlCopyFromFile')) {
            /** @var mixed $res */
            $res = $pdo->pgsqlCopyFromFile($table, $file, "\t", '\\\N', $fields);
        } else {
            throw new \Exception('PDO PostgreSQL driver does not support copyFromFile');
        }

        if (false === $res) {
            throw new \Exception("Failed to copy data from {$file} into table {$table}");
        }
    }
}
