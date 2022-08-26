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
    }

    public static function closeStaticExiftoolProc() {
        try {
            if (self::$staticProc) {
                fclose(self::$staticPipes[0]);
                fclose(self::$staticPipes[1]);
                fclose(self::$staticPipes[2]);
                proc_terminate(self::$staticProc);
            }
        } catch (\Exception $ex) {}
    }

    public static function ensureStaticExiftoolProc() {
        if (self::$noStaticProc) {
            return;
        }

        if (!self::$staticProc) {
            self::initializeStaticExiftoolProc();
            if (!proc_get_status(self::$staticProc)["running"]) {
                error_log("Failed to create stay_open exiftool process");
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
            return '/Photos/';
        }
        return $p;
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

    private static function getExifFromLocalPathWithStaticProc(string &$path) {
        fwrite(self::$staticPipes[0], "$path\n-json\n-execute\n");
        fflush(self::$staticPipes[0]);

        $buf = "";
        $readyToken = "\n{ready}\n";
        while (!str_ends_with($buf, $readyToken)) {
            $r = fread(self::$staticPipes[1], 1);
            if ($r === false) {
                error_log("Something went wrong with static exiftool process");
                exit(1);
            }
            $buf .= $r;
        }

        $buf = substr($buf, 0, strrpos($buf, $readyToken));
        return self::processStdout($buf);
    }

    private static function getExifFromLocalPathWithSeparateProc(string &$path) {
        $pipes = [];
        $proc = proc_open(['exiftool', '-json', $path], [
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        ], $pipes);
        $stdout = stream_get_contents($pipes[1]);

        // Clean up
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return self::processStdout($stdout);
    }

    /**
     * Get exif data as a JSON object from a stream.
     * @param resource $handle
     */
    public static function getExifFromStream(&$handle) {
        // Start exiftool and output to json
        $pipes = [];
        $proc = proc_open(['exiftool', '-json', '-fast', '-'], [
            0 => array('pipe', 'rb'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        ], $pipes);

        // Write the file to exiftool's stdin
        // Warning: this is slow for big files
        stream_copy_to_stream($handle, $pipes[0]);
        fclose($pipes[0]);

        // Get output from exiftool
        $stdout = stream_get_contents($pipes[1]);

        // Clean up
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return self::processStdout($stdout);
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
        $dt = $exif['DateTimeOriginal'];
        if (!isset($dt) || empty($dt)) {
            $dt = $exif['CreateDate'];
        }

        // Check if found something
        if (isset($dt) && !empty($dt)) {
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