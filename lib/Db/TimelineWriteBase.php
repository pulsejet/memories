<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\Exif;
use OCA\Memories\Settings\SystemConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

trait TimelineWriteBase
{
    public function __construct(
        protected IDBConnection $connection,
        protected LoggerInterface $logger,
        protected LivePhoto $livePhoto,
        protected ILockingProvider $lockingProvider,
        protected SystemConfig $systemConfig,
        protected Exif $exif,
    ) {}
}
