<?php

declare(strict_types=1);

if (!defined('PHPUNIT_RUN')) {
    define('PHPUNIT_RUN', 1);
}

require_once __DIR__.'/../vendor/autoload.php';

if (file_exists(__DIR__.'/../../../lib/base.php')) {
    require_once __DIR__.'/../../../lib/base.php';
}
