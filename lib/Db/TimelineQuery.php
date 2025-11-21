<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;

class TimelineQuery
{
    use TimelineQueryDays;
    use TimelineQueryFilters;
    use TimelineQueryFolders;
    use TimelineQueryLivePhoto;
    use TimelineQueryMap;
    use TimelineQueryNativeX;
    use TimelineQuerySingleItem;

    // Flag to control whether EXIF filtering happens at SQL level or PHP level
    // SQL level is faster but only available on MySQL/MariaDB
    // Set to false to force PHP-level filtering for debugging or compatibility
    protected bool $filterExifBySQL = true;

    public const TIMELINE_SELECT = [
        'm.datetaken', 'm.dayid',
        'm.w', 'm.h', 'm.liveid',
        'm.isvideo', 'm.video_duration',
        'm.exif',
        'f.etag', 'f.name AS basename',
        'f.size', 'm.epoch', // auid
        'mimetypes.mimetype',
    ];

    protected ?TimelineRoot $_root = null; // cache
    protected bool $_rootEmptyAllowed = false;

    public function __construct(
        protected IDBConnection $connection,
        protected IRequest $request,
        protected IUserManager $userManager,
    ) {}

    /**
     * Set whether EXIF filtering should happen at SQL level
     */
    public function setFilterExifBySQL(bool $value): void
    {
        $this->filterExifBySQL = $value;
    }

    /**
     * Check if we should filter EXIF at SQL level based on flag and database provider
     */
    public function shouldFilterExifBySQL(): bool
    {
        if (!$this->filterExifBySQL) {
            return false;
        }

        /** @var \OCP\IDBConnection $db */
        $dbProvider = $this->connection->getDatabaseProvider();

        if ($dbProvider === 'pgsql') {
            // PostgreSQL - could implement later if needed
            return false;
        } elseif ($dbProvider === 'mysql') {
            // MySQL/MariaDB - supports JSON operations
            return true;
        } elseif ($dbProvider === 'sqlite') {
            // SQLite - limited JSON support
            return false;
        } elseif ($dbProvider === 'oci') {
            // Oracle - complex JSON support
            return false;
        }

        return false;
    }

    public function allowEmptyRoot(bool $value = true): void
    {
        $this->_rootEmptyAllowed = $value;
    }

    public function getBuilder(): IQueryBuilder
    {
        return $this->connection->getQueryBuilder();
    }
}
