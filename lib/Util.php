<?php

declare(strict_types=1);

namespace OCA\Memories;

use OC\Files\Search\SearchBinaryOperator;
use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchQuery;
use OCP\App\IAppManager;
use OCP\Files\Node;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\IAppConfig;
use OCP\IConfig;

class Util
{
    use UtilController;

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
        if (!version_compare($v, '3.0.0-alpha', '>=')) {
            return false;
        }

        $c = \OC::$server->get(IAppConfig::class);
        if ('true' !== $c->getValue('recognize', 'faces.enabled', 'false')) {
            return false;
        }

        return true;
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
     * Force a fileinfo value on a node.
     * This is a hack to avoid subclassing everything.
     *
     * @param mixed $node  File to patch
     * @param mixed $key   Key to set
     * @param mixed $value Value to set
     */
    public static function forceFileInfo(Node &$node, $key, $value)
    {
        /** @var \OC\Files\Node\Node */
        $node = $node;
        $node->getFileInfo()[$key] = $value;
    }

    /**
     * Force permissions on a node.
     *
     * @param mixed $node        File to patch
     * @param mixed $permissions Permissions to set
     */
    public static function forcePermissions(Node &$node, int $permissions)
    {
        self::forceFileInfo($node, 'permissions', $permissions);
    }

    /**
     * Convert permissions to string.
     */
    public static function permissionsToStr(int $permissions): string
    {
        $str = '';
        if ($permissions & \OCP\Constants::PERMISSION_CREATE) {
            $str .= 'C';
        }
        if ($permissions & \OCP\Constants::PERMISSION_READ) {
            $str .= 'R';
        }
        if ($permissions & \OCP\Constants::PERMISSION_UPDATE) {
            $str .= 'U';
        }
        if ($permissions & \OCP\Constants::PERMISSION_DELETE) {
            $str .= 'D';
        }
        if ($permissions & \OCP\Constants::PERMISSION_SHARE) {
            $str .= 'S';
        }

        return $str;
    }

    /**
     * Add OG metadata to a page for a node.
     *
     * @param mixed $node        Node to get metadata from
     * @param mixed $title       Title of the page
     * @param mixed $url         URL of the page
     * @param mixed $previewArgs Preview arguments (e.g. token)
     */
    public static function addOgMetadata(Node $node, string $title, string $url, array $previewArgs)
    {
        // Add title
        \OCP\Util::addHeader('meta', ['property' => 'og:title', 'content' => $title]);

        // Get first node if folder
        if ($node instanceof \OCP\Files\Folder) {
            $query = new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_OR, [
                new SearchComparison(ISearchComparison::COMPARE_LIKE, 'mimetype', 'image/%'),
                new SearchComparison(ISearchComparison::COMPARE_LIKE, 'mimetype', 'video/%'),
            ]);
            $query = new SearchQuery($query, 1, 0, [], null);
            $nodes = $node->search($query);
            if (0 === \count($nodes)) {
                return;
            }
            $node = $nodes[0];
        }

        // Add file type
        $mimeType = $node->getMimeType();
        if (str_starts_with($mimeType, 'image/')) {
            \OCP\Util::addHeader('meta', ['property' => 'og:type', 'content' => 'image']);
        } elseif (str_starts_with($mimeType, 'video/')) {
            \OCP\Util::addHeader('meta', ['property' => 'og:type', 'content' => 'video']);
        }

        // Add OG url
        \OCP\Util::addHeader('meta', ['property' => 'og:url', 'content' => $url]);

        // Get URL generator
        $urlGenerator = \OC::$server->get(\OCP\IURLGenerator::class);

        // Add OG image
        $preview = $urlGenerator->linkToRouteAbsolute('memories.Image.preview', array_merge($previewArgs, [
            'id' => $node->getId(),
            'x' => 1024,
            'y' => 1024,
            'a' => true,
        ]));
        \OCP\Util::addHeader('meta', ['property' => 'og:image', 'content' => $preview]);
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
        // don't kill everything
        if (empty($name)) {
            return;
        }

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
