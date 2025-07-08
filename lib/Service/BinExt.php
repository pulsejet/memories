<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;

class BinExt
{
    public const EXIFTOOL_VER = '13.30';
    public const GOVOD_VER = '0.2.6';
    public const NX_VER_MIN = '1.1';

    /** Exiftool environment is initialized in this process */
    private static bool $hasExiftoolEnv = false;

    /** Get the path to the temp directory */
    public static function getTmpPath(): string
    {
        return SystemConfig::get('memories.exiftool.tmp') ?: sys_get_temp_dir();
    }

    /** Copy a binary to temp dir for execution */
    public static function getTempBin(string $path, string $name, bool $copy = true): string
    {
        // Bust cache if the path changes
        $suffix = hash('crc32', $path);

        // Check target temp file
        $target = self::getTmpPath().'/'.$name.'-'.$suffix;
        if (file_exists($target)) {
            if (!is_writable($target)) {
                throw new \Exception("{$name} temp binary path is not writable: {$target}");
            }

            if (!is_executable($target) && !chmod($target, 0755)) {
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

        return self::getTempBin($path, self::getName('exiftool', self::EXIFTOOL_VER));
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
            return ['perl', realpath(__DIR__.'/../../bin-ext/exiftool/exiftool')];
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

    /**
     * Get the upstream URL for a video.
     */
    public static function getGoVodUrl(string $client, string $path, string $profile): string
    {
        $path = rawurlencode($path);

        $bind = SystemConfig::get('memories.vod.bind');
        $connect = SystemConfig::get('memories.vod.connect', $bind);

        return "http://{$connect}/{$client}{$path}/{$profile}";
    }

    public static function getGoVodConfig(bool $local = false): array
    {
        // Get config from system values
        $env = [
            'qf' => SystemConfig::get('memories.vod.qf'),

            'vaapi' => SystemConfig::get('memories.vod.vaapi'),
            'vaapiLowPower' => SystemConfig::get('memories.vod.vaapi.low_power'),

            'nvenc' => SystemConfig::get('memories.vod.nvenc'),
            'nvencTemporalAQ' => SystemConfig::get('memories.vod.nvenc.temporal_aq'),
            'nvencScale' => SystemConfig::get('memories.vod.nvenc.scale'),

            'useTranspose' => SystemConfig::get('memories.vod.use_transpose'),
            'forceSwTranspose' => SystemConfig::get('memories.vod.use_transpose.force_sw'),
            'useGopSize' => SystemConfig::get('memories.vod.use_gop_size'),
        ];

        if (!$local) {
            return $env;
        }

        // Get temp directory
        $tmpPath = SystemConfig::get('memories.vod.tempdir', sys_get_temp_dir().'/go-vod/');

        // Make sure path ends with slash
        if ('/' !== substr($tmpPath, -1)) {
            $tmpPath .= '/';
        }

        // Add instance ID to path
        $tmpPath .= SystemConfig::get('instanceid');

        return array_merge($env, [
            'bind' => SystemConfig::get('memories.vod.bind'),
            'ffmpeg' => SystemConfig::get('memories.vod.ffmpeg'),
            'ffprobe' => SystemConfig::get('memories.vod.ffprobe'),
            'tempdir' => $tmpPath,
        ]);
    }

    /**
     * Get temp binary for go-vod.
     */
    public static function getGoVodBin(): string
    {
        $path = SystemConfig::get('memories.vod.path');

        return self::getTempBin($path, self::getName('go-vod', self::GOVOD_VER));
    }

    /**
     * If local, restart the go-vod instance.
     * If external, configure the go-vod instance.
     */
    public static function startGoVod(): ?string
    {
        // Check if disabled
        if (SystemConfig::get('memories.vod.disable')) {
            // Make sure it's dead, in case the user just disabled it
            self::pkill(self::getName('go-vod'));

            return null;
        }

        // Check if external
        if (SystemConfig::get('memories.vod.external')) {
            self::configureGoVod();

            return null;
        }

        // Get transcoder path
        $transcoder = self::getGoVodBin();
        if (empty($transcoder)) {
            throw new \Exception('Transcoder not configured');
        }

        // Get local config
        $env = self::getGoVodConfig(true);
        $tmpPath = $env['tempdir'];

        // (Re-)create temp dir
        Util::execSafe(['rm', '-rf', $tmpPath], 3000);
        mkdir($tmpPath, 0755, true);

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

        // Start transcoder
        // We need init to own this process, there's no easy way to do this
        $pipes = [];
        proc_open(['sh', '-c', "nohup {$transcoder} {$configFile} &"], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ], $pipes);

        // wait for 500ms
        usleep(500000);

        return $logFile;
    }

    /**
     * Test go-vod and (re)-start if it is not external.
     */
    public static function testStartGoVod(): string
    {
        try {
            return self::testGoVod();
        } catch (\Exception $e) {
            // silently try to restart
        }

        // Attempt to (re)start go-vod
        // If it is external, this only attempts to reconfigure
        self::startGoVod();

        // Test again
        return self::testGoVod();
    }

    /** Test the go-vod instance that is running */
    public static function testGoVod(): string
    {
        // Check if disabled
        if (SystemConfig::get('memories.vod.disable')) {
            throw new \Exception('Transcoding is disabled');
        }

        // TODO: check data mount; ignoring the result of the file for now
        $testfile = realpath(__DIR__.'/../../exiftest.jpg');

        // Make request
        $url = self::getGoVodUrl('test', $testfile, 'test');

        try {
            $client = new \GuzzleHttp\Client();
            $res = $client->request('GET', $url, [
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
     * POST a new configuration to go-vod.
     */
    public static function configureGoVod(): bool
    {
        // Get config
        $config = self::getGoVodConfig();

        // Make request
        $url = self::getGoVodUrl('config', '/config', 'config');

        try {
            $client = new \GuzzleHttp\Client();
            $client->request('POST', $url, [
                'json' => $config,
                'timeout' => 1,
                'connect_timeout' => 1,
            ]);
        } catch (\Exception $e) {
            throw new \Exception('failed to connect to go-vod: '.$e->getMessage());
        }

        return true;
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
                @chmod($goVodPath, 0755);
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
}
