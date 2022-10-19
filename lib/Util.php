<?php

declare(strict_types=1);

namespace OCA\Memories;

use OCA\Memories\AppInfo\Application;
use OCP\IConfig;

class Util
{
    public static $TAG_DAYID_START = -(1 << 30); // the world surely didn't exist
    public static $TAG_DAYID_FOLDERS = -(1 << 30) + 1;

    public static $ARCHIVE_FOLDER = '.archive';

    /**
     * Get the path to the user's configured photos directory.
     */
    public static function getPhotosPath(IConfig &$config, string $userId)
    {
        $p = $config->getUserValue($userId, Application::APPNAME, 'timelinePath', '');
        if (empty($p)) {
            return '/Photos/';
        }

        return $p;
    }
}
