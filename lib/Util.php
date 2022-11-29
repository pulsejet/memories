<?php

declare(strict_types=1);

namespace OCA\Memories;

class Util
{
    public static $TAG_DAYID_START = -(1 << 30); // the world surely didn't exist
    public static $TAG_DAYID_FOLDERS = -(1 << 30) + 1;

    public static $ARCHIVE_FOLDER = '.archive';

    /**
     * Get host CPU architecture (amd64 or aarch64).
     */
    public static function getArch()
    {
        $uname = php_uname('m');
        if (false !== stripos($uname, 'aarch64') || false !== stripos($uname, 'arm64')) {
            return 'aarch64';
        }
        if (false !== stripos($uname, 'x86_64') || false !== stripos($uname, 'amd64')) {
            return 'amd64';
        }

        return null;
    }

    /**
     * Get the libc type for host (glibc or musl).
     */
    public static function getLibc()
    {
        if ($ldd = shell_exec('ldd --version 2>&1')) {
            if (false !== stripos($ldd, 'musl')) {
                return 'musl';
            }
            if (false !== stripos($ldd, 'glibc')) {
                return 'glibc';
            }
        }

        return null;
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

        $v = $appManager->getAppVersion('photos');

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

        $v = $appManager->getAppVersion('recognize');

        return version_compare($v, '3.0.0-alpha', '>=');
    }

    /**
     * Check if link sharing is allowed.
     *
     * @param mixed $config
     */
    public static function isLinkSharingEnabled(&$config): bool
    {
        // Check if the shareAPI is enabled
        if ('yes' !== $config->getAppValue('core', 'shareapi_enabled', 'yes')) {
            return false;
        }

        // Check whether public sharing is enabled
        if ('yes' !== $config->getAppValue('core', 'shareapi_allow_links', 'yes')) {
            return false;
        }

        return true;
    }

    /**
     * Check if any encryption is enabled that we can not cope with
     * such as end-to-end encryption.
     *
     * @param mixed $encryptionManager
     */
    public static function isEncryptionEnabled(&$encryptionManager): bool
    {
        if ($encryptionManager->isEnabled()) {
            // Server-side encryption (OC_DEFAULT_MODULE) is okay, others like e2e are not
            return 'OC_DEFAULT_MODULE' !== $encryptionManager->getDefaultEncryptionModuleId();
        }

        return false;
    }
}
