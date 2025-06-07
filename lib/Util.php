<?php

declare(strict_types=1);

namespace OCA\Memories;

use OC\Files\Search\SearchBinaryOperator;
use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchQuery;
use OCA\Memories\AppInfo\Application;
use OCA\Memories\Settings\SystemConfig;
use OCP\App\IAppManager;
use OCP\Files\Node;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\IAppConfig;
use OCP\IConfig;

class Util
{
    use UtilController;

    public const ARCHIVE_FOLDER = '.archive';

    /**
     * Get host CPU architecture (amd64 or aarch64).
     *
     * @psalm-return 'aarch64'|'amd64'|null
     */
    public static function getArch(): ?string
    {
        $uname = strtolower(php_uname('m') ?: 'unknown');
        if (str_contains($uname, 'aarch64') || str_contains($uname, 'arm64')) {
            return 'aarch64';
        }
        if (str_contains($uname, 'x86_64') || str_contains($uname, 'amd64')) {
            return 'amd64';
        }

        return null;
    }

    /**
     * Get the libc type for host (glibc or musl).
     *
     * @psalm-return 'glibc'|'musl'|null
     */
    public static function getLibc(): ?string
    {
        // glibc -> stdout, musl -> stderr
        $output = self::execSafe2(['ldd', '--version'], 3000, null, true, true);

        // check in either
        $ldd = strtolower($output[0] ?? '')
            .strtolower($output[1] ?? '');

        if (str_contains($ldd, 'musl')) {
            return 'musl';
        }
        if (str_contains($ldd, 'glibc')) {
            return 'glibc';
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
        return \OC::$server->get(IAppManager::class)->isEnabledForUser('systemtags');
    }

    /**
     * Check if recognize is enabled for this user.
     */
    public static function recognizeIsEnabled(): bool
    {
        if (!self::recognizeIsInstalled()) {
            return false;
        }

        $appConfig = \OC::$server->get(IAppConfig::class);
        if ('true' !== $appConfig->getValueString('recognize', 'faces.enabled', 'false')) {
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

        return version_compare($v, '3.8.0', '>=');
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
            return 'true' === \OC::$server->get(IConfig::class)
                ->getUserValue(self::getUID(), 'facerecognition', 'enabled', 'false')
            ;
        } catch (\Exception) {
            // not logged in
        }

        return false;
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

        $v = $appManager->getAppVersion('facerecognition');

        return version_compare($v, '0.9.10-beta.2', '>=');
    }

    /**
     * Check if preview generator is installed.
     */
    public static function previewGeneratorIsEnabled(): bool
    {
        return \OC::$server->get(IAppManager::class)->isEnabledForUser('previewgenerator');
    }

    /**
     * Check if link sharing is allowed.
     *
     * @todo Check if link sharing is enabled to show the button
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function isLinkSharingEnabled(): bool
    {
        $appConfig = \OC::$server->get(IAppConfig::class);

        // Check if the shareAPI is enabled
        if ('yes' !== $appConfig->getValueString('core', 'shareapi_enabled', 'yes')) {
            return false;
        }

        // Check whether public sharing is enabled
        if ('yes' !== $appConfig->getValueString('core', 'shareapi_allow_links', 'yes')) {
            return false;
        }

        return true;
    }

    /**
     * Force permissions on a node.
     *
     * @param Node $node        File to patch
     * @param int  $permissions Permissions to set
     */
    public static function forcePermissions(Node &$node, int $permissions): void
    {
        /** @var \OC\Files\Node\Node $node */
        $fileInfo = $node->getFileInfo();

        /** @var \OC\Files\FileInfo $fileInfo */
        $fileInfo['permissions'] = $permissions;
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

        // Other permissions that are set elsewhere
        // L - Disable download (negative permission)

        return $str;
    }

    /**
     * Add OG metadata to a page for a node.
     *
     * @param Node   $node        Node to get metadata from
     * @param string $title       Title of the page
     * @param string $url         URL of the page
     * @param array  $previewArgs Preview arguments (e.g. token)
     */
    public static function addOgMetadata(Node $node, string $title, string $url, array $previewArgs): void
    {
        // Add title
        \OCP\Util::addHeader('meta', ['property' => 'og:title', 'content' => $title]);

        // Get first node if folder
        if ($node instanceof \OCP\Files\Folder) {
            if (null === ($node = self::getAnyMedia($node))) {
                return; // no media in folder
            }
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
     * Get a random image or video from a given folder.
     */
    public static function getAnyMedia(\OCP\Files\Folder $folder): ?Node
    {
        $query = new SearchQuery(new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_OR, [
            new SearchComparison(ISearchComparison::COMPARE_LIKE, 'mimetype', 'image/%'),
            new SearchComparison(ISearchComparison::COMPARE_LIKE, 'mimetype', 'video/%'),
        ]), 1, 0, [], null);

        $nodes = $folder->search($query);
        if (0 === \count($nodes)) {
            return null;
        }

        return $nodes[0];
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
     * Get list of timeline paths as array.
     *
     * @return string[] List of paths
     */
    public static function getTimelinePaths(string $uid): array
    {
        $paths = \OC::$server->get(IConfig::class)
            ->getUserValue($uid, Application::APPNAME, 'timelinePath', null)
                ?: SystemConfig::get('memories.timeline.default_path');

        return array_map(
            static fn ($path) => self::sanitizePath(trim($path))
                ?? throw new \InvalidArgumentException("Invalid timeline path: {$path}"),
            explode(';', $paths),
        );
    }

    /**
     * Run a callback in a transaction.
     * It returns the same type as the return type of the closure.
     *
     * @template T
     *
     * @psalm-param \Closure(): T $callback
     *
     * @psalm-return T
     */
    public static function transaction(\Closure $callback): mixed
    {
        $connection = \OC::$server->get(\OCP\IDBConnection::class);
        $connection->beginTransaction();

        try {
            $val = $callback();
            $connection->commit();

            return $val;
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    /**
     * Sanitize a path to keep only ASCII characters and special characters.
     * Null will be returned on error.
     */
    public static function sanitizePath(string $path): ?string
    {
        // remove double slashes and such
        $normalized = \OC\Files\Filesystem::normalizePath($path, false);

        // look for invalid characters and pattern
        if (!\OC\Files\Filesystem::isValidPath($normalized)) {
            return null;
        }

        return $normalized;
    }

    /**
     * Convert SQL UTC date to timestamp.
     */
    public static function sqlUtcToTimestamp(string $sqlDate): int
    {
        try {
            return (new \DateTime($sqlDate, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Explode a string into fixed number of components.
     *
     * @param non-empty-string $delimiter Delimiter
     * @param string           $string    String to explode
     * @param int              $count     Number of components
     *
     * @return string[] Array of components
     */
    public static function explode_exact(string $delimiter, string $string, int $count): array
    {
        return array_pad(explode($delimiter, $string, $count), $count, '');
    }

    /**
     * Checks if the API call was made from a native interface.
     */
    public static function callerIsNative(): bool
    {
        // Should not use IRequest here since this method is called during registration
        return 'gallery.memories' === ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')
        || str_contains($_SERVER['HTTP_USER_AGENT'] ?? '', 'MemoriesNative');
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
     * Register a signal handler with pcntl for SIGINT.
     */
    public static function registerInterruptHandler(string $name, callable $callback): void
    {
        // Only register signal handlers in CLI mode
        if (!\OC::$CLI || !\extension_loaded('pcntl')) {
            return;
        }

        // Register handler only once
        static $handlers = [];
        if ($handlers[$name] ?? null) {
            return;
        }

        // Check if this is the first handler
        $registered = \count($handlers) > 0;

        // Register handler
        $handlers[$name] = $callback;

        // pcntl_signal is already registered
        if ($registered) {
            return;
        }

        // Register handler
        pcntl_signal(SIGINT, static function () use ($handlers): void {
            foreach ($handlers as $handler) {
                $handler();
            }

            exit(1);
        });
    }

    /**
     * Execute a command safely.
     *
     * @param string[] $cmd     command to execute
     * @param int      $timeout milliseconds
     * @param ?string  $stdin   standard input
     *
     * @return string standard output
     *
     * @throws \Exception on error
     */
    public static function execSafe(array $cmd, int $timeout, ?string $stdin = null): ?string
    {
        return self::execSafe2($cmd, $timeout, $stdin, true, false)[0];
    }

    /** Exec safe with extra options */
    public static function execSafe2(array $cmd, int $timeout, ?string $stdin, bool $rstdout, bool $rstderr): array
    {
        $config = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        if (null !== $stdin) {
            $config[0] = ['pipe', 'r'];
        }

        $pipes = [];
        $proc = proc_open($cmd, $config, $pipes);
        if (!\is_resource($proc)) {
            throw new \Exception('proc_open failed: '.implode(' ', $cmd));
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        if (null !== $stdin) {
            fwrite($pipes[0], $stdin);
            fclose($pipes[0]);
        }

        try {
            $output = [null, null];

            if ($rstdout) {
                $output[0] = self::readOrTimeout($pipes[1], $timeout);
            }
            if ($rstderr) {
                $output[1] = self::readOrTimeout($pipes[2], $timeout);
            }

            return $output;
        } catch (\Exception $ex) {
            throw $ex;
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    /**
     * Read from non blocking handle or throw timeout.
     *
     * @param resource $handle
     * @param int      $timeout   milliseconds
     * @param string   $delimiter null for eof
     */
    public static function readOrTimeout($handle, int $timeout, ?string $delimiter = null): string
    {
        /** @psalm-suppress DocblockTypeContradiction */
        if (!\is_resource($handle)) {
            throw new \Exception('No resource read handle');
        }

        $buffer = '';

        // Absolute time to wait until
        $timeEnd = microtime(true) + $timeout / 1000;

        while (microtime(true) < $timeEnd) {
            // Check if we have delimiter or eof
            if (feof($handle) || ($delimiter && str_ends_with($buffer, $delimiter))) {
                return $buffer;
            }

            // Wait for data to read
            $read = [$handle];
            $write = $except = null;
            $ready = stream_select($read, $write, $except, 1, 0);
            if (false === $ready) {
                throw new \Exception('Stream select error');
            }

            // No data is available yet
            if (0 === $ready) {
                continue;
            }

            // Append to buffer
            $buffer .= stream_get_contents($handle);
        }

        throw new \Exception('Timeout');
    }
}
