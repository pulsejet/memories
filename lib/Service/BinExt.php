<?php

declare(strict_types=1);

namespace OCA\Memories\Service;

use OCA\Memories\Util;

class BinExt
{
    public const EXIFTOOL_VER = '12.60';
    public const GOVOD_VER = '0.1.16';
    public const NX_VER_MIN = '1.0';

    /** Get the path to the temp directory */
    public static function getTmpPath(): string {
        return Util::getSystemConfig('memories.exiftool.tmp') ?: sys_get_temp_dir();
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
        $id = Util::getInstanceId();

        return empty($version) ? "{$name}-{$id}" : "{$name}-{$id}-{$version}";
    }

    /** Test configured exiftool binary */
    public static function testExiftool(): string
    {
        $cmd = implode(' ', array_merge(self::getExiftool(), ['-ver']));
        $out = shell_exec($cmd);
        if (!$out) {
            throw new \Exception("failed to run exiftool: {$cmd}");
        }

        // Check version
        $version = trim($out);
        $target = self::EXIFTOOL_VER;
        if (!version_compare($version, $target, '=')) {
            throw new \Exception("version does not match {$version} <==> {$target}");
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
        $path = Util::getSystemConfig('memories.exiftool');

        return self::getTempBin($path, self::getName('exiftool', self::EXIFTOOL_VER));
    }

    /** Get path to exiftool binary for proc_open */
    public static function getExiftool(): array
    {
        if (Util::getSystemConfig('memories.exiftool_no_local')) {
            return ['perl', realpath(__DIR__.'/../../exiftool-bin/exiftool/exiftool')];
        }

        return [self::getExiftoolPBin()];
    }

    /** Detect the exiftool binary to use */
    public static function detectExiftool()
    {
        if (!empty($path = Util::getSystemConfig('memories.exiftool'))) {
            return $path;
        }

        if (Util::getSystemConfig('memories.exiftool_no_local')) {
            return implode(' ', self::getExiftool());
        }

        // Detect architecture
        $arch = \OCA\Memories\Util::getArch();
        $libc = \OCA\Memories\Util::getLibc();

        // Get static binary if available
        if ($arch && $libc) {
            // get target file path
            $path = realpath(__DIR__."/../../exiftool-bin/exiftool-{$arch}-{$libc}");

            // make sure it exists
            if ($path && file_exists($path)) {
                Util::setSystemConfig('memories.exiftool', $path);

                return $path;
            }
        }

        Util::setSystemConfig('memories.exiftool_no_local', true);

        return false;
    }

    /**
     * Get the upstream URL for a video.
     */
    public static function getGoVodUrl(string $client, string $path, string $profile): string
    {
        $path = rawurlencode($path);

        $bind = Util::getSystemConfig('memories.vod.bind');
        $connect = Util::getSystemConfig('memories.vod.connect', $bind);

        return "http://{$connect}/{$client}{$path}/{$profile}";
    }

    public static function getGoVodConfig($local = false)
    {
        // Get config from system values
        $env = [
            'vaapi' => Util::getSystemConfig('memories.vod.vaapi'),
            'vaapiLowPower' => Util::getSystemConfig('memories.vod.vaapi.low_power'),

            'nvenc' => Util::getSystemConfig('memories.vod.nvenc'),
            'nvencTemporalAQ' => Util::getSystemConfig('memories.vod.nvenc.temporal_aq'),
            'nvencScale' => Util::getSystemConfig('memories.vod.nvenc.scale'),

            'useTranspose' => Util::getSystemConfig('memories.vod.use_transpose'),
            'useGopSize' => Util::getSystemConfig('memories.vod.use_gop_size'),
        ];

        if (!$local) {
            return $env;
        }

        // Get temp directory
        $tmpPath = Util::getSystemConfig('memories.vod.tempdir', sys_get_temp_dir().'/go-vod/');

        // Make sure path ends with slash
        if ('/' !== substr($tmpPath, -1)) {
            $tmpPath .= '/';
        }

        // Add instance ID to path
        $tmpPath .= Util::getInstanceId();

        return array_merge($env, [
            'bind' => Util::getSystemConfig('memories.vod.bind'),
            'ffmpeg' => Util::getSystemConfig('memories.vod.ffmpeg'),
            'ffprobe' => Util::getSystemConfig('memories.vod.ffprobe'),
            'tempdir' => $tmpPath,
        ]);
    }

    /**
     * Get temp binary for go-vod.
     */
    public static function getGoVodBin()
    {
        $path = Util::getSystemConfig('memories.vod.path');

        return self::getTempBin($path, self::getName('go-vod', self::GOVOD_VER));
    }

    /**
     * If local, restart the go-vod instance.
     * If external, configure the go-vod instance.
     */
    public static function startGoVod()
    {
        // Check if disabled
        if (Util::getSystemConfig('memories.vod.disable')) {
            // Make sure it's dead, in case the user just disabled it
            Util::pkill(self::getName('go-vod'));

            return;
        }

        // Check if external
        if (Util::getSystemConfig('memories.vod.external')) {
            self::configureGoVod();

            return;
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
        shell_exec("rm -rf '{$tmpPath}' && mkdir -p '{$tmpPath}' && chmod 755 '{$tmpPath}'");

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
        Util::pkill(self::getName('go-vod'));

        // Start transcoder
        shell_exec("nohup {$transcoder} {$configFile} >> '{$logFile}' 2>&1 & > /dev/null");

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
        if (Util::getSystemConfig('memories.vod.disable')) {
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
            throw new \Exception("version does not match {$version} <==> {$target}");
        }

        return $version;
    }

    /** POST a new configuration to go-vod */
    public static function configureGoVod()
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

    /** Detect the go-vod binary to use */
    public static function detectGoVod()
    {
        $goVodPath = Util::getSystemConfig('memories.vod.path');

        if (empty($goVodPath) || !file_exists($goVodPath)) {
            // Detect architecture
            $arch = \OCA\Memories\Util::getArch();
            $path = __DIR__."/../../exiftool-bin/go-vod-{$arch}";
            $goVodPath = realpath($path);

            if (!$goVodPath) {
                return false;
            }

            // Set config
            Util::setSystemConfig('memories.vod.path', $goVodPath);

            // Make executable
            if (!is_executable($goVodPath)) {
                @chmod($goVodPath, 0755);
            }
        }

        return $goVodPath;
    }

    public static function detectFFmpeg()
    {
        $ffmpegPath = Util::getSystemConfig('memories.vod.ffmpeg');
        $ffprobePath = Util::getSystemConfig('memories.vod.ffprobe');

        if (empty($ffmpegPath) || !file_exists($ffmpegPath) || empty($ffprobePath) || !file_exists($ffprobePath)) {
            // Use PATH
            $ffmpegPath = shell_exec('which ffmpeg');
            $ffprobePath = shell_exec('which ffprobe');
            if (!$ffmpegPath || !$ffprobePath) {
                return false;
            }

            // Trim
            $ffmpegPath = trim($ffmpegPath);
            $ffprobePath = trim($ffprobePath);

            // Set config
            Util::setSystemConfig('memories.vod.ffmpeg', $ffmpegPath);
            Util::setSystemConfig('memories.vod.ffprobe', $ffprobePath);
        }

        // Check if executable
        if (!is_executable($ffmpegPath) || !is_executable($ffprobePath)) {
            return false;
        }

        return $ffmpegPath;
    }

    public static function testFFmpeg(string $path, string $name)
    {
        $version = shell_exec("{$path} -version");
        if (!preg_match("/{$name} version \\S*/", $version, $matches)) {
            throw new \Exception("failed to detect version, found {$version}");
        }

        return explode(' ', $matches[0])[2];
    }

    public static function testSystemPerl(string $path): string
    {
        if (($out = shell_exec("{$path} -e 'print \"OK\";'")) !== 'OK') {
            throw new \Exception('Failed to run test perl script: '.$out);
        }

        return shell_exec("{$path} -e 'print $^V;'");
    }
}
