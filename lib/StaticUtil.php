<?php

declare(strict_types=1);

namespace OCA\Memories;

use OC\Files\Search\SearchBinaryOperator;
use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchQuery;
use OCP\Files\Node;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;

class StaticUtil
{
    public const string ARCHIVE_FOLDER = '.archive';

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
        pcntl_signal(SIGINT, static function () use (&$handlers): void {
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
     * @param ?resource $handle
     * @param int       $timeout   milliseconds
     * @param ?string   $delimiter null for eof
     */
    public static function readOrTimeout($handle, int $timeout, ?string $delimiter = null): string
    {
        /** @psalm-suppress DocblockTypeContradiction */
        if (!\is_resource($handle)) {
            throw new \Exception('No resource read handle');
        }

        $buffer = '';

        // Absolute time to wait until
        /** @psalm-suppress InvalidOperand */
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
            if ($contents = stream_get_contents($handle)) {
                $buffer .= $contents;
            }
        }

        throw new \Exception('Timeout');
    }
}
