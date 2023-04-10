<?php

namespace OCA\Memories;

class BinExt
{
    public const EXIFTOOL_VER = '12.58';

    /** Test configured exiftool binary */
    public static function testExiftool(): bool
    {
        $cmd = implode(' ', array_merge(self::getExiftool(), ['-ver']));
        $out = shell_exec($cmd);
        if (!$out) {
            throw new \Exception('failed to run exiftool');
        }

        $version = trim($out);
        $target = self::EXIFTOOL_VER;
        if (!version_compare($version, $target, '=')) {
            throw new \Exception("version does not match {$version} <==> {$target}");
        }

        return true;
    }

    /** Get path to exiftool binary for proc_open */
    public static function getExiftool(): array
    {
        if (Util::getSystemConfig('memories.exiftool_no_local')) {
            return ['perl', __DIR__.'/../exiftool-bin/exiftool/exiftool'];
        }

        return [Util::getSystemConfig('memories.exiftool')];
    }

    /** Detect the exiftool binary to use */
    public static function detectExiftool()
    {
        if (!empty($path = Util::getSystemConfig('memories.exiftool'))) {
            if (file_exists($path) && !is_executable($path)) {
                @chmod($path, 0755);
            }

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
            $path = realpath(__DIR__."/../exiftool-bin/exiftool-{$arch}-{$libc}");

            // Set config
            Util::setSystemConfig('memories.exiftool', $path);

            // make sure it is executable
            if (file_exists($path)) {
                if (!is_executable($path)) {
                    @chmod($path, 0755);
                }

                return $path;
            }
        }

        Util::setSystemConfig('memories.exiftool_no_local', true);

        return false;
    }

    /** Detect the go-vod binary to use */
    public static function detectGoVod()
    {
        $goVodPath = Util::getSystemConfig('memories.vod.path');

        if (empty($goVodPath) || !file_exists($goVodPath)) {
            // Detect architecture
            $arch = \OCA\Memories\Util::getArch();
            $path = __DIR__."/../exiftool-bin/go-vod-{$arch}";
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
}
