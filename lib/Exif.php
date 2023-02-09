<?php

declare(strict_types=1);

namespace OCA\Memories;

use OCA\Memories\AppInfo\Application;
use OCP\Files\File;
use OCP\IConfig;

class Exif
{
    private const EXIFTOOL_VER = '12.50';
    private const EXIFTOOL_TIMEOUT = 30000;

    /** Opened instance of exiftool when running in command mode */
    private static $staticProc;
    private static $staticPipes;
    private static $noStaticProc = false;

    public static function closeStaticExiftoolProc()
    {
        try {
            if (self::$staticProc) {
                fclose(self::$staticPipes[0]);
                fclose(self::$staticPipes[1]);
                fclose(self::$staticPipes[2]);
                proc_terminate(self::$staticProc);
                self::$staticProc = null;
                self::$staticPipes = null;
            }
        } catch (\Exception $ex) {
        }
    }

    public static function restartStaticExiftoolProc()
    {
        self::closeStaticExiftoolProc();
        self::ensureStaticExiftoolProc();
    }

    public static function ensureStaticExiftoolProc()
    {
        if (self::$noStaticProc) {
            return;
        }

        if (!self::$staticProc) {
            self::initializeStaticExiftoolProc();
            usleep(500000); // wait if error
            if (!proc_get_status(self::$staticProc)['running']) {
                error_log('WARN: Failed to create stay_open exiftool process');
                self::$noStaticProc = true;
                self::$staticProc = null;
            }

            return;
        }

        if (!proc_get_status(self::$staticProc)['running']) {
            self::$staticProc = null;
            self::ensureStaticExiftoolProc();
        }
    }

    /**
     * Get the path to the user's configured photos directory.
     */
    public static function getPhotosPath(IConfig &$config, string &$userId)
    {
        $p = $config->getUserValue($userId, Application::APPNAME, 'timelinePath', '');
        if (empty($p)) {
            return 'Photos/';
        }

        return self::sanitizePath($p);
    }

    /**
     * Sanitize a path to keep only ASCII characters and special characters.
     */
    public static function sanitizePath(string $path)
    {
        return mb_ereg_replace('([^\\w\\s\\d\\-_~,;:!@#$&*{}\[\]\'\\[\\]\\(\\).\\\/])', '', $path);
    }

    /**
     * Keep only one slash if multiple repeating.
     */
    public static function removeExtraSlash(string $path)
    {
        return mb_ereg_replace('\/\/+', '/', $path);
    }

    /**
     * Remove any leading slash present on the path.
     */
    public static function removeLeadingSlash(string $path)
    {
        return mb_ereg_replace('~^/+~', '', $path);
    }

    /**
     * Get exif data as a JSON object from a Nextcloud file.
     */
    public static function getExifFromFile(File &$file)
    {
        $path = $file->getStorage()->getLocalFile($file->getInternalPath());
        if (!\is_string($path)) {
            throw new \Exception('Failed to get local file path');
        }

        $exif = self::getExifFromLocalPath($path);

        // We need to remove blacklisted fields to prevent leaking info
        unset($exif['SourceFile'], $exif['FileName'], $exif['ExifToolVersion'], $exif['Directory'], $exif['FileSize'], $exif['FileModifyDate'], $exif['FileAccessDate'], $exif['FileInodeChangeDate'], $exif['FilePermissions'], $exif['ThumbnailImage']);

        return $exif;
    }

    /** Get exif data as a JSON object from a local file path */
    public static function getExifFromLocalPath(string &$path)
    {
        if (null !== self::$staticProc) {
            self::ensureStaticExiftoolProc();

            return self::getExifFromLocalPathWithStaticProc($path);
        }

        return self::getExifFromLocalPathWithSeparateProc($path);
    }

    /**
     * Parse date from exif format and throw error if invalid.
     *
     * @param mixed $date
     *
     * @return int unix timestamp
     */
    public static function parseExifDate($date)
    {
        $dt = $date;
        if (isset($dt) && \is_string($dt) && !empty($dt)) {
            $dt = explode('-', explode('+', $dt, 2)[0], 2)[0]; // get rid of timezone if present
            $dt = \DateTime::createFromFormat('Y:m:d H:i:s', $dt);
            if (!$dt) {
                throw new \Exception("Invalid date: {$date}");
            }
            if ($dt && $dt->getTimestamp() > -5364662400) { // 1800 A.D.
                return $dt->getTimestamp();
            }

            throw new \Exception("Date too old: {$date}");
        } else {
            throw new \Exception('No date provided');
        }
    }

    /**
     * Forget the timezone for an epoch timestamp and get the same
     * time epoch for UTC.
     */
    public static function forgetTimezone(int $epoch)
    {
        $dt = new \DateTime();
        $dt->setTimestamp($epoch);
        $tz = getenv('TZ'); // at least works on debian ...
        if ($tz) {
            $dt->setTimezone(new \DateTimeZone($tz));
        }
        $utc = new \DateTime($dt->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));

        return $utc->getTimestamp();
    }

    /**
     * Get the date taken from either the file or exif data if available.
     *
     * @return int unix timestamp
     */
    public static function getDateTaken(File &$file, array &$exif)
    {
        // Try to parse the date from exif metadata
        $dt = $exif['DateTimeOriginal'] ?? null;

        try {
            return self::parseExifDate($dt);
        } catch (\Exception $ex) {
        } catch (\ValueError $ex) {
        }

        // Fall back to modification time
        $dateTaken = $file->getMtime();

        return self::forgetTimezone($dateTaken);
    }

    /**
     * Get image dimensions from Exif data.
     *
     * @return array [width, height]
     */
    public static function getDimensions(array &$exif)
    {
        $width = $exif['ImageWidth'] ?? 0;
        $height = $exif['ImageHeight'] ?? 0;

        // Check if image is rotated and we need to swap width and height
        $rotation = $exif['Rotation'] ?? 0;
        $orientation = $exif['Orientation'] ?? 0;
        if (\in_array($orientation, [5, 6, 7, 8], true) || \in_array($rotation, [90, 270], true)) {
            return [$height, $width];
        }

        if ($width <= 0 || $height <= 0 || $width > 100000 || $height > 100000) {
            return [0, 0];
        }

        return [$width, $height];
    }

    /**
     * Set exif data using raw json.
     *
     * @param string $path to local file
     * @param array  $data exif data
     *
     * @throws \Exception on failure
     */
    public static function setExif(string &$path, array &$data)
    {
        $data['SourceFile'] = $path;
        $raw = json_encode([$data]);
        $cmd = array_merge(self::getExiftool(), ['-json=-', $path]);
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        fwrite($pipes[0], $raw);
        fclose($pipes[0]);

        $stdout = self::readOrTimeout($pipes[1], self::EXIFTOOL_TIMEOUT);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($proc);
        if (false !== strpos($stdout, 'error')) {
            error_log("Exiftool error: {$stdout}");

            throw new \Exception('Could not set exif data: '.$stdout);
        }
    }

    public static function getBinaryExifProp(string $path, string $prop)
    {
        $pipes = [];
        $proc = proc_open(array_merge(self::getExiftool(), [$prop, '-n', '-b', $path]), [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        stream_set_blocking($pipes[1], false);

        try {
            return self::readOrTimeout($pipes[1], self::EXIFTOOL_TIMEOUT);
        } catch (\Exception $ex) {
            error_log("Exiftool timeout: [{$path}]");

            throw new \Exception('Could not read from Exiftool');
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($proc);
        }
    }

    public static function getExifWithDuplicates(string $path)
    {
        return self::getExifFromLocalPathWithSeparateProc($path, ['-G4']);
    }

    /** Get path to exiftool binary */
    private static function getExiftool()
    {
        $configKey = 'memories.exiftool';
        $config = \OC::$server->get(IConfig::class);
        $configPath = $config->getSystemValue($configKey);
        $noLocal = $config->getSystemValue($configKey.'_no_local', false);

        // We know already where it is
        if (!empty($configPath) && file_exists($configPath)) {
            if (!is_executable($configPath)) {
                chmod($configPath, 0755);
            }

            return explode(' ', $configPath);
        }

        // Detect architecture
        $arch = $noLocal ? null : \OCA\Memories\Util::getArch();
        $libc = $noLocal ? null : \OCA\Memories\Util::getLibc();

        // Get static binary if available
        if ($arch && $libc && !$noLocal) {
            // get target file path
            $path = realpath(__DIR__."/../exiftool-bin/exiftool-{$arch}-{$libc}");

            // check if file exists
            if (file_exists($path)) {
                // make executable before version check
                if (!is_executable($path)) {
                    chmod($path, 0755);
                }

                // check if the version prints correctly
                $ver = self::EXIFTOOL_VER;
                $vero = shell_exec("{$path} -ver");
                if ($vero && false !== stripos(trim($vero), $ver)) {
                    $out = trim($vero);
                    echo "Exiftool binary version check passed {$out} <==> {$ver}\n";
                    $config->setSystemValue($configKey, $path);

                    return [$path];
                }
                error_log("Exiftool version check failed {$vero} <==> {$ver}");
                $config->setSystemValue($configKey.'_no_local', true);
            } else {
                error_log("Exiftool not found: {$path}");
            }
        }

        // Fallback to perl script
        $path = __DIR__.'/../exiftool-bin/exiftool/exiftool';
        if (file_exists($path)) {
            return ['perl', $path];
        }

        error_log("Exiftool not found: {$path}");

        // Fallback to system binary
        return ['exiftool'];
    }

    /** Initialize static exiftool process for local reads */
    private static function initializeStaticExiftoolProc()
    {
        self::closeStaticExiftoolProc();
        self::$staticProc = proc_open(array_merge(self::getExiftool(), ['-stay_open', 'true', '-@', '-']), [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], self::$staticPipes);
        stream_set_blocking(self::$staticPipes[1], false);
    }

    /**
     * Read from non blocking handle or throw timeout.
     *
     * @param resource $handle
     * @param int      $timeout   milliseconds
     * @param string   $delimiter null for eof
     */
    private static function readOrTimeout($handle, int $timeout, ?string $delimiter = null)
    {
        $buf = '';
        $waitedMs = 0;

        while ($waitedMs < $timeout && ($delimiter ? !str_ends_with($buf, $delimiter) : !feof($handle))) {
            $r = stream_get_contents($handle);
            if (empty($r)) {
                ++$waitedMs;
                usleep(1000);

                continue;
            }
            $buf .= $r;
        }

        if ($waitedMs >= $timeout) {
            throw new \Exception('Timeout');
        }

        return $buf;
    }

    private static function getExifFromLocalPathWithStaticProc(string &$path)
    {
        fwrite(self::$staticPipes[0], "{$path}\n-U\n-json\n--b\n-api\nQuickTimeUTC=1\n-n\n-execute\n");
        fflush(self::$staticPipes[0]);

        $readyToken = "\n{ready}\n";

        try {
            $buf = self::readOrTimeout(self::$staticPipes[1], self::EXIFTOOL_TIMEOUT, $readyToken);
            $tokPos = strrpos($buf, $readyToken);
            $buf = substr($buf, 0, $tokPos);

            return self::processStdout($buf);
        } catch (\Exception $ex) {
            error_log("ERROR: Exiftool may have crashed, restarting process [{$path}]");
            self::restartStaticExiftoolProc();

            throw new \Exception('Nothing to read from Exiftool');
        }
    }

    private static function getExifFromLocalPathWithSeparateProc(string &$path, array $extraArgs = [])
    {
        $pipes = [];
        $proc = proc_open(array_merge(self::getExiftool(), ['-api', 'QuickTimeUTC=1', '-n', '-U', '-json', '--b'], $extraArgs, [$path]), [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        stream_set_blocking($pipes[1], false);

        try {
            $stdout = self::readOrTimeout($pipes[1], self::EXIFTOOL_TIMEOUT);

            return self::processStdout($stdout);
        } catch (\Exception $ex) {
            error_log("Exiftool timeout: [{$path}]");

            throw new \Exception('Could not read from Exiftool');
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($proc);
        }
    }

    /** Get json array from stdout of exiftool */
    private static function processStdout(string &$stdout)
    {
        $json = json_decode($stdout, true);
        if (!$json) {
            throw new \Exception('Could not read exif data');
        }

        return $json[0];
    }
}
