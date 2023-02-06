<?php

declare(strict_types=1);

namespace OCA\Memories;

use OCP\App\IAppManager;
use OCP\IConfig;

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
     */
    public static function albumsIsEnabled(IAppManager &$appManager): bool
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
     */
    public static function recognizeIsEnabled(IAppManager &$appManager): bool
    {
        if (!$appManager->isEnabledForUser('recognize')) {
            return false;
        }

        $v = $appManager->getAppVersion('recognize');

        return version_compare($v, '3.0.0-alpha', '>=');
    }

    /**
     * Check if Face Recognition is enabled by the user.
     */
    public static function facerecognitionIsEnabled(IConfig &$config, string $userId): bool
    {
        $e = $config->getUserValue($userId, 'facerecognition', 'enabled', 'false');

        return 'true' === $e;
    }

    /**
     * Check if Face Recognition is installed and enabled for this user.
     */
    public static function facerecognitionIsInstalled(IAppManager &$appManager): bool
    {
        if (!$appManager->isEnabledForUser('facerecognition')) {
            return false;
        }

        $v = $appManager->getAppInfo('facerecognition')['version'];

        return version_compare($v, '0.9.10-beta.2', '>=');
    }

    /**
     * Check if link sharing is allowed.
     */
    public static function isLinkSharingEnabled(IConfig &$config): bool
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
     */
    public static function isEncryptionEnabled(): bool
    {
        $encryptionManager = \OC::$server->get(\OCP\Encryption\IManager::class);
        if ($encryptionManager->isEnabled()) {
            // Server-side encryption (OC_DEFAULT_MODULE) is okay, others like e2e are not
            return 'OC_DEFAULT_MODULE' !== $encryptionManager->getDefaultEncryptionModuleId();
        }

        return false;
    }

    /**
     * Check if geolocation (places) is enabled and available.
     * Returns the type of the GIS.
     */
    public static function placesGISType(): int
    {
        return (int) \OC::$server->get(\OCP\IConfig::class)->getSystemValue('memories.gis_type', -1);
    }

    /**
     * Kill all instances of a process by name.
     * Similar to pkill, which may not be available on all systems.
     */
    public static function pkill(string $name): void
    {
        // get pids using ps as array
        $pids = shell_exec("ps -ef | grep {$name} | grep -v grep | awk '{print $2}'");
        if (null === $pids || empty($pids)) {
            return;
        }
        $pids = array_filter(explode("\n", $pids));

        // kill all pids
        foreach ($pids as $pid) {
            posix_kill((int) $pid, 9); // SIGKILL
        }
    }
}
