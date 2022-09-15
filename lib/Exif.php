<?php
declare(strict_types=1);

namespace OCA\Memories;

use OCA\Memories\AppInfo\Application;
use OCP\Files\File;
use OCP\IConfig;

class Exif {
    /** Opened instance of exiftool when running in command mode */
    private static $staticProc = null;
    private static $staticPipes = null;
    private static $noStaticProc = false;

    /** Initialize static exiftool process for local reads */
    private static function initializeStaticExiftoolProc() {
        self::closeStaticExiftoolProc();
        self::$staticProc = proc_open(['exiftool', '-stay_open', 'true', '-@', '-'], [
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        ], self::$staticPipes);
        stream_set_blocking(self::$staticPipes[1], false);
    }

    public static function closeStaticExiftoolProc() {
        try {
            if (self::$staticProc) {
                fclose(self::$staticPipes[0]);
                fclose(self::$staticPipes[1]);
                fclose(self::$staticPipes[2]);
                proc_terminate(self::$staticProc);
                self::$staticProc = null;
                self::$staticPipes = null;
            }
        } catch (\Exception $ex) {}
    }

    public static function restartStaticExiftoolProc() {
        self::closeStaticExiftoolProc();
        self::ensureStaticExiftoolProc();
    }

    public static function ensureStaticExiftoolProc() {
        if (self::$noStaticProc) {
            return;
        }

        if (!self::$staticProc) {
            self::initializeStaticExiftoolProc();
            usleep(500000); // wait if error
            if (!proc_get_status(self::$staticProc)["running"]) {
                error_log("WARN: Failed to create stay_open exiftool process");
                self::$noStaticProc = true;
                self::$staticProc = null;
            }
            return;
        }

        if (!proc_get_status(self::$staticProc)["running"]) {
            self::$staticProc = null;
            self::ensureStaticExiftoolProc();
        }
    }

    /**
     * Get the path to the user's configured photos directory.
     * @param IConfig $config
     * @param string $userId
     */
    public static function getPhotosPath(IConfig &$config, string &$userId) {
        $p = $config->getUserValue($userId, Application::APPNAME, 'timelinePath', '');
        if (empty($p)) {
            return 'Photos/';
        }
        return self::sanitizePath($p);
    }

    /**
     * Sanitize a path to keep only ASCII characters and special characters.
     * @param string $path
     */
    public static function sanitizePath(string $path) {
        return mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).\/])", '', $path);
    }

    /**
     * Keep only one slash if multiple repeating
     */
    public static function removeExtraSlash(string $path) {
        return mb_ereg_replace('\/\/+', '/', $path);
    }

    /**
     * Remove any leading slash present on the path
     */
    public static function removeLeadingSlash(string $path) {
        return mb_ereg_replace('~^/+~', '', $path);
    }

    /**
     * Get exif data as a JSON object from a Nextcloud file.
     * @param File $file
     */
    public static function getExifFromFile(File &$file) {
        // Borrowed from previews
        // https://github.com/nextcloud/server/blob/19f68b3011a3c040899fb84975a28bd746bddb4b/lib/private/Preview/ProviderV2.php
        if (!$file->isEncrypted() && $file->getStorage()->isLocal()) {
            $path = $file->getStorage()->getLocalFile($file->getInternalPath());
            if (is_string($path)) {
                return self::getExifFromLocalPath($path);
            }
        }

        // Fallback to reading as a stream
        $handle = $file->fopen('rb');
        if (!$handle) {
            throw new \Exception('Could not open file');
        }

        $exif = self::getExifFromStream($handle);
        fclose($handle);
        return $exif;
    }

    /** Get exif data as a JSON object from a local file path */
    public static function getExifFromLocalPath(string &$path) {
        if (!is_null(self::$staticProc)) {
            self::ensureStaticExiftoolProc();
            return self::getExifFromLocalPathWithStaticProc($path);
        } else {
            return self::getExifFromLocalPathWithSeparateProc($path);
        }
    }

    /**
     * Read from non blocking handle or throw timeout
     * @param resource $handle
     * @param int $timeout milliseconds
     * @param string $delimiter null for eof
     */
    private static function readOrTimeout($handle, $timeout, $delimiter=null) {
        $buf = '';
        $waitedMs = 0;

        while ($waitedMs < $timeout && ($delimiter ? !str_ends_with($buf, $delimiter) : !feof($handle))) {
            $r = stream_get_contents($handle);
            if (empty($r)) {
                $waitedMs++;
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

    private static function getExifFromLocalPathWithStaticProc(string &$path) {
        fwrite(self::$staticPipes[0], "$path\n-json\n-api\nQuickTimeUTC=1\n-execute\n");
        fflush(self::$staticPipes[0]);

        $readyToken = "\n{ready}\n";

        try {
            $buf = self::readOrTimeout(self::$staticPipes[1], 5000, $readyToken);
            $tokPos = strrpos($buf, $readyToken);
            $buf = substr($buf, 0, $tokPos);
            return self::processStdout($buf);
        } catch (\Exception $ex) {
            error_log("ERROR: Exiftool may have crashed, restarting process [$path]");
            self::restartStaticExiftoolProc();
            throw new \Exception("Nothing to read from Exiftool");
        }
    }

    private static function getExifFromLocalPathWithSeparateProc(string &$path) {
        $pipes = [];
        $proc = proc_open(['exiftool', '-api', 'QuickTimeUTC=1', '-json', $path], [
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        ], $pipes);
        stream_set_blocking($pipes[1], false);

        try {
            $stdout = self::readOrTimeout($pipes[1], 5000);
            return self::processStdout($stdout);
        } catch (\Exception $ex) {
            error_log("Exiftool timeout: [$path]");
            throw new \Exception("Could not read from Exiftool");
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($proc);
        }
    }

    /**
     * Get exif data as a JSON object from a stream.
     * @param resource $handle
     */
    public static function getExifFromStream(&$handle) {
        // Start exiftool and output to json
        $pipes = [];
        $proc = proc_open(['exiftool', '-api', 'QuickTimeUTC=1', '-json', '-fast', '-'], [
            0 => array('pipe', 'rb'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        ], $pipes);

        // Write the file to exiftool's stdin
        // Warning: this is slow for big files
        // Copy a maximum of 20MB; this may be $$$
        stream_copy_to_stream($handle, $pipes[0], 20 * 1024 * 1024);
        fclose($pipes[0]);

        // Get output from exiftool
        stream_set_blocking($pipes[1], false);
        try {
            $stdout = self::readOrTimeout($pipes[1], 5000);
            return self::processStdout($stdout);
        } catch (\Exception $ex) {
            error_log("Exiftool timeout for file stream: " . $ex->getMessage());
            throw new \Exception("Could not read from Exiftool");
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($proc);
        }
    }

    /** Get json array from stdout of exiftool */
    private static function processStdout(string &$stdout) {
        $json = json_decode($stdout, true);
        if (!$json) {
            throw new \Exception('Could not read exif data');
        }
        return $json[0];
    }

    /**
     * Get the date taken from either the file or exif data if available.
     * @param File $file
     * @param array $exif
     */
    public static function getDateTaken(File &$file, array &$exif) {
        $dt = $exif['DateTimeOriginal'] ?? null;
        if (!isset($dt) || empty($dt)) {
            $dt = $exif['CreateDate'] ?? null;
        }

        // Check if found something
        if (isset($dt) && is_string($dt) && !empty($dt)) {
            $dt = explode('-', explode('+', $dt, 2)[0], 2)[0]; // get rid of timezone if present
            $dt = \DateTime::createFromFormat('Y:m:d H:i:s', $dt, new \DateTimeZone("UTC"));
            if ($dt && $dt->getTimestamp() > -5364662400) { // 1800 A.D.
                return $dt->getTimestamp();
            }
        }

        // Fall back to creation time
        $dateTaken = $file->getCreationTime();

        // Fall back to modification time
        if ($dateTaken == 0) {
            $dateTaken = $file->getMtime();
        }
        return $dateTaken;
    }
}