<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;

trait TimelineQueryBase
{
    /** @var list<string> */
    public const array TIMELINE_SELECT = [
        'm.datetaken', 'm.dayid',
        'm.w', 'm.h', 'm.liveid',
        'm.isvideo', 'm.video_duration',
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
        protected SystemConfig $systemConfig,
        protected Util $util,
    ) {}

    public function allowEmptyRoot(bool $value = true): void
    {
        $this->_rootEmptyAllowed = $value;
    }

    public function getBuilder(): IQueryBuilder
    {
        return $this->connection->getQueryBuilder();
    }
}
