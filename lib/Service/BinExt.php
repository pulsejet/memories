<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;

final class BinExt
{
    public const EXIFTOOL_VER = '13.59';
    public const GOVOD_VER = '0.4.2';
    public const NX_VER_MIN = '1.1';

    private const GO_VOD_PID_FILE = '/tmp/go-vod.pid';
    private const GO_VOD_LOCK_FILE = '/tmp/go-vod.lock';

    /** Exiftool environment is initialized in this process */
    private static bool $hasExiftoolEnv = false;

    /** Get the path to the temp directory */
    public static function getTmpPath(): string
    {
        $path = SystemConfig::get('memories.exiftool.tmp');

        return rtrim($path ?: sys_get_temp_dir(), '/');
    }

    /** Copy a binary to temp dir for execution */
    public static function getTempBin(string $path, string $name, bool $copy = true): string
    {
        // Bust cache if the path changes
        $suffix = hash('crc32', $path);

        // Check target temp file
        $target = self::getTmpPath().'/'.$name.'-'.$suffix;
        if (file_exists($target)) {
            if (!is_executable($target) && !chmod($target, 0o755)) {
                throw new \Exception("failed to make {$name} temp binary executable: {$target}");
            }

            return $target;
        }

        if ($copy) {
            if (empty($path)) {
                throw new \Exception('binary path is empty (run occ maintenance:repair or use system perl)');
            }

            if (!copy($path, $target)) {
                throw new \Exception("failed to copy {$name} binary from {$path} to {$target}");
            }

            return self::getTempBin($path, $name, false);
        }

        throw new \Exception("failed to find exiftool temp binary {$target}");
    }

    /** Get the name for a binary */
    public static function getName(string $name, string $version = ''): string
    {
        $id = SystemConfig::get('instanceid');

        return empty($version) ? "{$name}-{$id}" : "{$name}-{$id}-{$version}";
    }

    /** Test configured exiftool binary */
    public static function testExiftool(): string
    {
        $cmd = array_merge(self::getExiftool(), ['-ver']);

        $out = Util::execSafe($cmd, 3000);
        if (!$out) {
            throw new \Exception('failed to run exiftool: '.implode(' ', $cmd));
        }

        // Check version
        $version = trim($out);
        $target = self::EXIFTOOL_VER;
        if (!version_compare($version, $target, '=')) {
            throw new \Exception("exiftool version does not match: expected {$target} but found {$version}");
        }

        // Test with actual file
        $file = realpath(__DIR__.'/../../exiftest.jpg');
        if (!$file) {
            throw new \Exception('Could not find EXIF test file');
        }

        try {
            $exif = \OCA\Memories\Exif::getExifFromLocalPath($file);
        } catch (\Exception $e) {
            throw new \Exception("Couldn't read Exif data from test file: ".$e->getMessage());
        }

        if (!$exif) {
            throw new \Exception('Got no Exif data from test file');
        }

        if (($exp = '2004:08:31 19:52:58') !== ($got = $exif['DateTimeOriginal'])) {
            throw new \Exception("Got wrong Exif data from test file {$exp} <==> {$got}");
        }

        return $version;
    }

    /** Get path to exiftool binary */
    public static function getExiftoolPBin(): string
    {
        $path = SystemConfig::get('memories.exiftool');

        $path = self::getTempBin($path, self::getName('exiftool', self::EXIFTOOL_VER));

        // Explicitly set the PAR directory to avoid cache collisions
        // https://github.com/pulsejet/memories/issues/1608
        putenv("PAR_GLOBAL_TEMP={$path}.out");

        return $path;
    }

    /**
     * Get path to exiftool binary for proc_open.
     *
     * @return string[]
     */
    public static function getExiftool(): array
    {
        if (!self::$hasExiftoolEnv) {
            self::$hasExiftoolEnv = true;
            putenv('LANG=C'); // set perl lang to suppress warning
        }

        if (SystemConfig::get('memories.exiftool_no_local')) {
            $path = realpath(__DIR__.'/../../bin-ext/exiftool/exiftool') ?: '';

            return ['perl', $path];
        }

        return [self::getExiftoolPBin()];
    }

    /**
     * Detect the exiftool binary to use.
     */
    public static function detectExiftool(): false|string
    {
        if (!empty($path = SystemConfig::get('memories.exiftool')) && file_exists($path)) {
            return $path;
        }

        if (SystemConfig::get('memories.exiftool_no_local')) {
            return implode(' ', self::getExiftool());
        }

        // Detect architecture
        $arch = \OCA\Memories\Util::getArch();
        $libc = \OCA\Memories\Util::getLibc();

        // Get static binary if available
        if ($arch && $libc) {
            // get target file path
            $path = realpath(__DIR__."/../../bin-ext/exiftool-{$arch}-{$libc}");

            // make sure it exists
            if ($path && file_exists($path)) {
                SystemConfig::set('memories.exiftool', $path);

                return $path;
            }
        }

        SystemConfig::set('memories.exiftool_no_local', true);

        return false;
    }

    /** Get the upstream URL for a go-vod API (vod, create). */
    public static function getGoVodEndpoint(string $endpoint): string
    {
        $connect = $bind = SystemConfig::get('memories.vod.bind');
        if (SystemConfig::get('memories.vod.external')) {
            $connect = SystemConfig::get('memories.vod.connect', $bind);
        }

        return "http://{$connect}/{$endpoint}";
    }

    public static function goVodTConfig(): array
    {
        // Get config from system values
        return [
            'chunkSize' => 3,
            'qf' => SystemConfig::get('memories.vod.qf'),

            'vaapi' => SystemConfig::get('memories.vod.vaapi'),
            'vaapiLowPower' => SystemConfig::get('memories.vod.vaapi.low_power'),
            'vaapiDevice' => SystemConfig::get('memories.vod.vaapi.device'),

            'nvenc' => SystemConfig::get('memories.vod.nvenc'),
            'nvencTemporalAQ' => SystemConfig::get('memories.vod.nvenc.temporal_aq'),
            'nvencScale' => SystemConfig::get('memories.vod.nvenc.scale'),

            'useTranspose' => SystemConfig::get('memories.vod.use_transpose'),
            'forceSwTranspose' => SystemConfig::get('memories.vod.use_transpose.force_sw'),
            'useGopSize' => SystemConfig::get('memories.vod.use_gop_size'),
        ];
    }

    public static function goVodServerConfig(): array
    {
        $dir = static function (string $key, string $default): string {
            return rtrim(SystemConfig::get($key, $default), '/')
                .'/'.SystemConfig::get('instanceid');
        };

        return [
            'bind' => SystemConfig::get('memories.vod.bind'),
            'ffmpeg' => SystemConfig::get('memories.vod.ffmpeg'),
            'ffprobe' => SystemConfig::get('memories.vod.ffprobe'),
            'tempdir' => $dir('memories.vod.tempdir', sys_get_temp_dir().'/go-vod/'),
            'cacheDir' => $dir('memories.vod.cachedir', sys_get_temp_dir().'/go-vod-cache'),
        ];
    }

    /**
     * Get temp binary for go-vod.
     */
    public static function getGoVodBin(): string
    {
        $path = SystemConfig::get('memories.vod.path');

        return self::getTempBin($path, self::getName('go-vod', self::GOVOD_VER));
    }

    public static function ensureGoVod(): void
    {
        if (SystemConfig::get('memories.vod.disable') || SystemConfig::get('memories.vod.external')) {
            return;
        }

        if (self::isGoVodAlive()) {
            return;
        }

        // Serialize concurrent starters (e.g. parallel segment requests)
        $lock = @fopen(self::GO_VOD_LOCK_FILE, 'c');
        if (false !== $lock) {
            flock($lock, LOCK_EX);
        }

        try {
            // Re-check after acquiring the lock
            if (self::isGoVodAlive()) {
                return;
            }

            // Get transcoder path
            $transcoder = self::getGoVodBin();
            if (empty($transcoder)) {
                throw new \Exception('Transcoder not configured');
            }

            // Get local server config
            $env = self::goVodServerConfig();
            $tmpPath = $env['tempdir'];

            // (Re-)create temp dir
            Util::execSafe(['rm', '-rf', $tmpPath], 3000);
            mkdir($tmpPath, 0o755, true);

            // Check temp directory exists
            if (!is_dir($tmpPath)) {
                throw new \Exception("Temp directory could not be created ({$tmpPath})");
            }

            // Check temp directory is writable
            if (!is_writable($tmpPath)) {
                throw new \Exception("Temp directory is not writable ({$tmpPath})");
            }

            // Write config to file
            $logFile = $tmpPath.'.log';
            $configFile = $tmpPath.'.json';
            file_put_contents($configFile, json_encode($env, JSON_PRETTY_PRINT));

            // Kill the transcoder in case it's running
            self::pkill(self::getName('go-vod'));

            // Spawn detached via shell backgrounding so init adopts go-vod.
            // An abandoned proc_open handle would leave a zombie under the PHP worker.
            $shell = \sprintf(
                'nohup %s %s >> %s 2>&1 & echo $!',
                escapeshellarg($transcoder),
                escapeshellarg($configFile),
                escapeshellarg($logFile),
            );

            $pipes = [];
            $proc = proc_open(['sh', '-c', $shell], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ], $pipes);

            $childPid = 0;
            if (\is_resource($proc)) {
                $childPid = (int) trim((string) stream_get_contents($pipes[1]));
                fclose($pipes[1]);
                proc_close($proc); // reaps sh; go-vod is adopted by init
            }

            // Record the pid for liveness checks
            if ($childPid > 0) {
                @file_put_contents(self::GO_VOD_PID_FILE, (string) $childPid);
            }

            // wait for 500ms
            usleep(500000);
        } finally {
            if (false !== $lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** Test the go-vod instance that is running */
    public static function testGoVod(): string
    {
        // Check if disabled
        if (SystemConfig::get('memories.vod.disable')) {
            throw new \Exception('Transcoding is disabled');
        }

        // Ensure transcoder is running
        self::ensureGoVod();

        // TODO: check data mount; ignoring the result of the file for now
        $testfile = realpath(__DIR__.'/../../exiftest.jpg');

        // Make request
        $url = self::getGoVodEndpoint('vod');

        try {
            $client = new \GuzzleHttp\Client();
            $res = $client->request('POST', $url, [
                'json' => [
                    'client' => 'test',
                    'fileid' => 0,
                    'etag' => '',
                    'path' => $testfile,
                    'profile' => 'test',
                    'query' => '',
                    'config' => self::goVodTConfig(),
                ],
                'timeout' => 1,
                'connect_timeout' => 1,
            ]);
        } catch (\Exception $e) {
            throw new \Exception('failed to connect to go-vod: '.$e->getMessage());
        }

        // Parse body
        $json = json_decode((string) $res->getBody(), true);
        if (!$json) {
            throw new \Exception('failed to parse go-vod response');
        }

        // Check version
        $version = $json['version'];
        $target = self::GOVOD_VER;
        if (!version_compare($version, $target, '=')) {
            throw new \Exception("govod version does not match: expected {$target} but found {$version}");
        }

        return $version;
    }

    /**
     * Detect the go-vod binary to use.
     */
    public static function detectGoVod(): false|string
    {
        $goVodPath = SystemConfig::get('memories.vod.path');

        if (empty($goVodPath) || !file_exists($goVodPath)) {
            // Detect architecture
            $arch = \OCA\Memories\Util::getArch();
            $path = __DIR__."/../../bin-ext/go-vod-{$arch}";
            $goVodPath = realpath($path);

            if (!$goVodPath) {
                return false;
            }

            // Set config
            SystemConfig::set('memories.vod.path', $goVodPath);

            // Make executable
            if (!is_executable($goVodPath)) {
                @chmod($goVodPath, 0o755);
            }
        }

        return $goVodPath;
    }

    public static function detectFFmpeg(): ?string
    {
        $ffmpegPath = SystemConfig::get('memories.vod.ffmpeg');
        $ffprobePath = SystemConfig::get('memories.vod.ffprobe');

        if (empty($ffmpegPath) || !file_exists($ffmpegPath) || empty($ffprobePath) || !file_exists($ffprobePath)) {
            // Use PATH environment variable to find ffmpeg
            $ffmpegPath = Util::execSafe(['which', 'ffmpeg'], 3000);
            $ffprobePath = Util::execSafe(['which', 'ffprobe'], 3000);
            if (!$ffmpegPath || !$ffprobePath) {
                return null;
            }

            // Trim
            $ffmpegPath = trim($ffmpegPath);
            $ffprobePath = trim($ffprobePath);

            // Set config
            SystemConfig::set('memories.vod.ffmpeg', $ffmpegPath);
            SystemConfig::set('memories.vod.ffprobe', $ffprobePath);
        }

        // Check if executable
        if (!is_executable($ffmpegPath) || !is_executable($ffprobePath)) {
            return null;
        }

        return $ffmpegPath;
    }

    public static function testFFmpeg(string $path, string $name): string
    {
        $version = Util::execSafe([$path, '-version'], 3000) ?: '';
        if (!preg_match("/{$name} version \\S*/", $version, $matches)) {
            throw new \Exception("failed to detect version, found {$version}");
        }

        return explode(' ', $matches[0])[2];
    }

    public static function testSystemPerl(string $path): string
    {
        if (($out = Util::execSafe([$path, '-e', 'print "OK";'], 3000)) !== 'OK') {
            throw new \Exception('Failed to run test perl script: '.(string) $out);
        }

        return Util::execSafe([$path, '-e', 'print $^V;'], 3000) ?: 'unknown version';
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
        $ps = ['ps'];

        if (!Util::execSafe(['which', 'ps'], 1000)) {
            if (!Util::execSafe(['which', 'busybox'], 1000)) {
                return;
            }

            $ps = ['busybox', 'ps'];
        }

        $procs = Util::execSafe(array_merge($ps, ['-eao', 'pid,comm']), 1000) ?: '';
        $procs = explode("\n", $procs);

        $matches = array_filter($procs, static fn ($l) => str_contains($l, $name));
        $pids = array_map(static fn ($l) => (int) explode(' ', trim($l))[0], $matches);
        if (empty($pids)) {
            return;
        }

        foreach ($pids as $pid) {
            posix_kill($pid, 9); // SIGKILL
        }
    }

    /** Check if the local go-vod instance is alive */
    private static function isGoVodAlive(): bool
    {
        $pid = (int) @file_get_contents(self::GO_VOD_PID_FILE);
        if ($pid <= 0) {
            return false;
        }

        // Fast path: Linux /proc
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if (\is_string($stat) && false !== ($end = strrpos($stat, ')'))) {
            // Check process state (Z = zombie, X = dead)
            if (\in_array(trim(substr($stat, $end + 1, 2)), ['Z', 'X'], true)) {
                return false;
            }

            // Guard against PID reuse: make sure it is actually go-vod
            $cmdline = @file_get_contents("/proc/{$pid}/cmdline");

            return \is_string($cmdline) && str_contains($cmdline, 'go-vod');
        }

        // Fallback: ps for systems without procfs (e.g. FreeBSD).
        // Only POSIX flags so this works on Linux, FreeBSD and macOS.
        try {
            $out = trim((string) Util::execSafe(['ps', '-o', 'stat=', '-o', 'command=', '-p', (string) $pid], 1000));
        } catch (\Exception) {
            return false;
        }
        if ('' === $out) {
            return false;
        }

        $parts = preg_split('/\s+/', $out, 2);
        if (!$parts || str_starts_with($parts[0], 'Z')) {
            return false;
        }

        return isset($parts[1]) && str_contains($parts[1], 'go-vod');
    }
}
