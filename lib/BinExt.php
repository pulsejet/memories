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
    public static function detectExiftool(): void
    {
        if (!empty($path = Util::getSystemConfig('memories.exiftool'))) {
            if (file_exists($path) && !is_executable($path)) {
                chmod($path, 0755);
            }

            return;
        }

        if (Util::getSystemConfig('memories.exiftool_no_local')) {
            return;
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
                    chmod($path, 0755);
                }
            } else {
                error_log("Exiftool binary not found: {$path}");
                Util::setSystemConfig('memories.exiftool_no_local', true);
            }
        }
    }
}
