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

    /**
     * Check if albums are enabled for this user.
     *
     * @param mixed $appManager
     */
    public static function albumsIsEnabled(&$appManager): bool
    {
        if (!$appManager->isEnabledForUser('photos')) {
            return false;
        }

        $v = $appManager->getAppInfo('photos')['version'];

        return version_compare($v, '1.7.0', '>=');
    }

    /**
     * Check if tags is enabled for this user.
     *
     * @param mixed $appManager
     */
    public static function tagsIsEnabled(&$appManager): bool
    {
        return $appManager->isEnabledForUser('systemtags');
    }

    /**
     * Check if recognize is enabled for this user.
     *
     * @param mixed $appManager
     */
    public static function recognizeIsEnabled(&$appManager): bool
    {
        if (!$appManager->isEnabledForUser('recognize')) {
            return false;
        }

        $v = $appManager->getAppInfo('recognize')['version'];

        return version_compare($v, '3.0.0-alpha', '>=');
    }
}
