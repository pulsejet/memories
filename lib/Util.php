<?php

declare(strict_types=1);

namespace OCA\Memories;

use OC\Files\Search\SearchBinaryOperator;
use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchQuery;
use OCA\Memories\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Files\Node;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\IAppConfig;
use OCP\IConfig;

class Util
{
    use UtilController;

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
    public static function albumsIsEnabled(): bool
    {
        $appManager = \OC::$server->get(IAppManager::class);

        if (!$appManager->isEnabledForUser('photos')) {
            return false;
        }

        $v = $appManager->getAppVersion('photos');

        return version_compare($v, '1.7.0', '>=');
    }

    /**
     * Check if tags is enabled for this user.
     */
    public static function tagsIsEnabled(): bool
    {
        $appManager = \OC::$server->get(IAppManager::class);

        return $appManager->isEnabledForUser('systemtags');
    }

    /**
     * Check if recognize is enabled for this user.
     */
    public static function recognizeIsEnabled(): bool
    {
        if (!self::recognizeIsInstalled()) {
            return false;
        }

        $config = \OC::$server->get(IAppConfig::class);
        if ('true' !== $config->getValue('recognize', 'faces.enabled', 'false')) {
            return false;
        }

        return true;
    }

    /**
     * Check if recognize is installed.
     */
    public static function recognizeIsInstalled(): bool
    {
        $appManager = \OC::$server->get(IAppManager::class);

        if (!$appManager->isEnabledForUser('recognize')) {
            return false;
        }

        $v = $appManager->getAppVersion('recognize');
        if (!version_compare($v, '3.8.0', '>=')) {
            return false;
        }

        return true;
    }

    /**
     * Check if Face Recognition is enabled by the user.
     */
    public static function facerecognitionIsEnabled(): bool
    {
        if (!self::facerecognitionIsInstalled()) {
            return false;
        }

        try {
            $uid = self::getUID();
        } catch (\Exception $e) {
            return false;
        }

        $enabled = \OC::$server->get(IConfig::class)
            ->getUserValue($uid, 'facerecognition', 'enabled', 'false')
        ;

        return 'true' === $enabled;
    }

    /**
     * Check if Face Recognition is installed and enabled for this user.
     */
    public static function facerecognitionIsInstalled(): bool
    {
        $appManager = \OC::$server->get(IAppManager::class);

        if (!$appManager->isEnabledForUser('facerecognition')) {
            return false;
        }

        $v = $appManager->getAppInfo('facerecognition')['version'];

        return version_compare($v, '0.9.10-beta.2', '>=');
    }

    /**
     * Check if preview generator is installed.
     */
    public static function previewGeneratorIsEnabled(): bool
    {
        $appManager = \OC::$server->get(IAppManager::class);

        return $appManager->isEnabledForUser('previewgenerator');
    }

    /**
     * Check if link sharing is allowed.
     */
    public static function isLinkSharingEnabled(): bool
    {
        $config = \OC::$server->get(IConfig::class);

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
        return self::getSystemConfig('memories.gis_type');
    }

    /**
     * Get list of timeline paths as array.
     */
    public static function getTimelinePaths(string $uid): array
    {
        $config = \OC::$server->get(IConfig::class);
        $paths = $config->getUserValue($uid, Application::APPNAME, 'timelinePath', null)
            ?? self::getSystemConfig('memories.timeline.default_path');

        return array_map(static fn ($p) => self::sanitizePath(trim($p)), explode(';', $paths));
    }

    /**
     * Sanitize a path to keep only ASCII characters and special characters.
     */
    public static function sanitizePath(string $path): string
    {
        $path = str_replace("\0", '', $path); // remove null characters

        return mb_ereg_replace('\/\/+', '/', $path); // remove extra slashes
    }

    /**
     * Convert SQL UTC date to timestamp.
     *
     * @param mixed $sqlDate
     */
    public static function sqlUtcToTimestamp($sqlDate): int
    {
        try {
            return (new \DateTime($sqlDate, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Explode a string into fixed number of components.
     *
     * @param string $delimiter Delimiter
     * @param string $string    String to explode
     * @param int    $count     Number of components
     *
     * @return string[] Array of components
     */
    public static function explode_exact(string $delimiter, string $string, int $count): array
    {
        return array_pad(explode($delimiter, $string, $count), $count, '');
    }

    /**
     * Get a system config key with the correct default.
     *
     * @param string     $key     System config key
     * @param null|mixed $default Default value
     */
    public static function getSystemConfig(string $key, $default = null)
    {
        $config = \OC::$server->get(\OCP\IConfig::class);

        $defaults = self::systemConfigDefaults();
        if (!\array_key_exists($key, $defaults)) {
            throw new \InvalidArgumentException("Invalid system config key: {$key}");
        }

        return $config->getSystemValue($key, $default ?? $defaults[$key]);
    }

    /**
     * Set a system config key.
     *
     * @param mixed $value
     *
     * @throws \InvalidArgumentException
     */
    public static function setSystemConfig(string $key, $value): void
    {
        $config = \OC::$server->get(\OCP\IConfig::class);

        // Check if the key is valid
        $defaults = self::systemConfigDefaults();
        if (!\array_key_exists($key, $defaults)) {
            throw new \InvalidArgumentException("Invalid system config key: {$key}");
        }

        // Key belongs to memories namespace
        $isAppKey = str_starts_with($key, Application::APPNAME.'.');

        // Check if the value has the correct type
        if (null !== $value && \gettype($value) !== \gettype($defaults[$key])) {
            $expected = \gettype($defaults[$key]);
            $got = \gettype($value);

            throw new \InvalidArgumentException("Invalid type for system config {$key}, expected {$expected}, got {$got}");
        }

        // Do not allow null for non-app keys
        if (!$isAppKey && null === $value) {
            throw new \InvalidArgumentException("Invalid value for system config {$key}, null is not allowed");
        }

        if ($isAppKey && ($value === $defaults[$key] || null === $value)) {
            $config->deleteSystemValue($key);
        } else {
            $config->setSystemValue($key, $value);
        }
    }

    /** Get list of defaults for all system config keys. */
    public static function systemConfigDefaults(): array
    {
        return require __DIR__.'/SystemConfigDefault.php';
    }

    /**
     * Get the instance ID for this instance.
     */
    public static function getInstanceId(): string
    {
        return self::getSystemConfig('instanceid');
    }

    /**
     * Checks if the API call was made from a native interface.
     */
    public static function callerIsNative(): bool
    {
        // Should not use IRequest here since this method is called during registration
        if (\array_key_exists('HTTP_X_REQUESTED_WITH', $_SERVER)) {
            return 'gallery.memories' === $_SERVER['HTTP_X_REQUESTED_WITH'];
        }

        return false !== strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'MemoriesNative');
    }

    /**
     * Get the version of the native caller.
     */
    public static function callerNativeVersion(): ?string
    {
        $userAgent = \OC::$server->get(\OCP\IRequest::class)->getHeader('User-Agent');

        $matches = [];
        if (preg_match('/MemoriesNative\/([0-9.]+)/', $userAgent, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Kill all instances of a process by name.
     * Similar to pkill, which may not be available on all systems.
     *
     * @param string $name Process name (only the first 12 characters are used)
     */
    public static function pkill(string $name): void
    {
        // don't kill everything
        if (empty($name)) {
            return;
        }

        // only use the first 12 characters
        $name = substr($name, 0, 12);

        // check if ps or busybox is available
        $ps = 'ps';
        if (!shell_exec('which ps')) {
            if (!shell_exec('which busybox')) {
                return;
            }

            $ps = 'busybox ps';
        }

        // get pids using ps as array
        $pids = shell_exec("{$ps} -eao pid,comm | grep {$name} | awk '{print $1}'");
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
