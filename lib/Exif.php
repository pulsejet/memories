<?php

declare(strict_types=1);

namespace OCA\Memories;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Service\BinExt;
use OCP\Files\File;

class Exif
{
    private const FORBIDDEN_EDIT_MIMES = ['image/bmp', 'image/x-dcraw', 'video/MP2T']; // also update const.ts
    private const EXIFTOOL_TIMEOUT = 30000;
    private const EXIFTOOL_ARGS = ['-api', 'QuickTimeUTC=1', '-n', '-json'];

    /** Opened instance of exiftool when running in command mode */
    /** @var null|resource */
    private static $staticProc;

    /** @var null|resource[] */
    private static $staticPipes;

    /** Disable uisage of static process */
    private static bool $noStaticProc = false;

    public static function closeStaticExiftoolProc(): void
    {
        try {
            // Close I/O pipes
            if (self::$staticPipes) {
                fclose(self::$staticPipes[0]);
                fclose(self::$staticPipes[1]);
                fclose(self::$staticPipes[2]);
                self::$staticPipes = null;
            }

            // Close process
            if (self::$staticProc) {
                proc_terminate(self::$staticProc);
                proc_close(self::$staticProc);
                self::$staticProc = null;
            }
        } catch (\Exception $ex) {
        }
    }

    public static function restartStaticExiftoolProc(): void
    {
        self::closeStaticExiftoolProc();
        self::ensureStaticExiftoolProc();
    }

    public static function ensureStaticExiftoolProc(): void
    {
        if (self::$noStaticProc) {
            return;
        }

        if (!self::$staticProc) {
            self::initializeStaticExiftoolProc();
            usleep(500000); // wait if error

            /** @psalm-suppress NullArgument */
            if (!proc_get_status(self::$staticProc)['running']) {
                error_log('WARN: Failed to create stay_open exiftool process');
                self::$noStaticProc = true;
                self::$staticProc = null;
                self::$staticPipes = null;
            }

            return;
        }

        if (!proc_get_status(self::$staticProc)['running']) {
            self::$staticProc = null;
            self::$staticPipes = null;
            self::ensureStaticExiftoolProc();
        }
    }

    /**
     * Get exif data as a JSON object from a Nextcloud file.
     *
     * @return array<string, mixed>
     */
    public static function getExifFromFile(File $file): array
    {
        try {
            $path = $file->getStorage()->getLocalFile($file->getInternalPath());
        } catch (\Throwable $ex) {
            // https://github.com/pulsejet/memories/issues/820
            throw new \Exception("Failed to get local file: {$ex->getMessage()}");
        }

        // Check if path is valid
        if (!\is_string($path)) {
            throw new \Exception('Failed to get local file path');
        }

        // Check if file is readable
        if (!is_readable($path)) {
            throw new \Exception("File is not readable: {$path}");
        }

        $exif = self::getExifFromLocalPath($path);

        // We need to remove blacklisted fields to prevent leaking info
        unset($exif['SourceFile'], $exif['FileName'], $exif['ExifToolVersion'], $exif['Directory'], $exif['FileSize'], $exif['FileModifyDate'], $exif['FileAccessDate'], $exif['FileInodeChangeDate'], $exif['FilePermissions'], $exif['ThumbnailImage']);

        // Ignore zero dates
        $dateFields = [
            'DateTimeOriginal',
            'SubSecDateTimeOriginal',
            'CreateDate',
            'ModifyDate',
            'TrackCreateDate',
            'TrackModifyDate',
            'MediaCreateDate',
            'MediaModifyDate',
        ];
        foreach ($dateFields as $field) {
            if (\array_key_exists($field, $exif) && \is_string($exif[$field]) && str_starts_with($exif[$field], '0000:00:00')) {
                unset($exif[$field]);
            }
        }

        return $exif;
    }

    /**
     * Get exif data as a JSON object from a local file path.
     *
     * @return array<string, mixed>
     */
    public static function getExifFromLocalPath(string $path): array
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
     * @param array<string, mixed> $exif
     */
    public static function parseExifDate(array $exif): \DateTime
    {
        // Get date from exif
        $exifDate = $exif['DateTimeOriginal'] ?? $exif['CreateDate'] ?? null;

        // For videos, prefer CreateDate for timezone (QuickTimeUTC=1)
        if (preg_match('/^video\/\w+/', (string) ($exif['MIMEType'] ?? null))) {
            $exifDate = $exif['CreateDate'] ?? $exifDate;
        }

        // Check if we have a date
        if (null === $exifDate || empty($exifDate) || !\is_string($exifDate)) {
            throw new \Exception('No date found in exif');
        }

        // Get timezone from exif
        try {
            $tzStr = $exif['OffsetTimeOriginal']
                ?? $exif['OffsetTime']
                ?? $exif['LocationTZID']
                ?? throw new \Exception();
            $exifTz = new \DateTimeZone((string) $tzStr);
        } catch (\Exception) {
            $exifTz = null;
        }

        // Force UTC if no timezone found
        $parseTz = $exifTz ?? new \DateTimeZone('UTC');

        // https://github.com/pulsejet/memories/pull/397
        // https://github.com/pulsejet/memories/issues/485

        $formats = [
            'Y:m:d H:i', // 2023:03:05 18:58
            'Y:m:d H:iO', // 2023:03:05 18:58+05:00
            'Y:m:d H:i:s', // 2023:03:05 18:58:17
            'Y:m:d H:i:sO', // 2023:03:05 10:58:17+05:00
            'Y:m:d H:i:s.u', // 2023:03:05 10:58:17.000
            'Y:m:d H:i:s.uO', // 2023:03:05 10:58:17.000Z
        ];

        /** @var \DateTime $dt */
        $parsedDate = null;

        foreach ($formats as $format) {
            if ($parsedDate = \DateTime::createFromFormat($format, $exifDate, $parseTz)) {
                break;
            }
        }

        // If we couldn't parse the date, throw an error
        if (!$parsedDate) {
            throw new \Exception("Invalid date: {$exifDate}");
        }

        // Epoch timestamp
        $timestamp = $parsedDate->getTimestamp();

        // Filter out dates before 1800 A.D.
        if ($timestamp < -5364662400) { // 1800 A.D.
            throw new \Exception("Date too old: {$exifDate}");
        }

        // Filter out January 1, 1904 12:00:00 AM UTC
        // Exiftool returns this as the date when QuickTimeUTC is set and
        // the date is set to 0000:00:00 00:00:00
        if (-2082844800 === $timestamp) {
            throw new \Exception("Blacklisted date: {$exifDate}");
        }

        // Force the timezone to be the same as parseTz
        if ($exifTz) {
            $parsedDate->setTimezone($exifTz);
        }

        return $parsedDate;
    }

    /**
     * Get the date taken from either the file or exif data if available.
     *
     * @param array<string, mixed> $exif
     */
    public static function getDateTaken(File $file, array $exif): \DateTime
    {
        try {
            return self::parseExifDate($exif);
        } catch (\Exception) {
        } catch (\ValueError) {
        }

        // Fall back to modification time
        $dt = new \DateTime('@'.$file->getMtime());

        // Set timezone to system timezone
        $tz = getenv('TZ') ?: date_default_timezone_get();

        try {
            $dt->setTimezone(new \DateTimeZone($tz));
        } catch (\Exception) {
            throw new \Error("FATAL: system timezone is invalid (TZ): {$tz}");
        }

        return $dt;
    }

    /**
     * Convert time to local date in UTC.
     */
    public static function forgetTimezone(\DateTime $date): \DateTime
    {
        return new \DateTime($date->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }

    /**
     * Get image dimensions from Exif data.
     *
     * @param array<string, mixed> $exif
     *
     * @return int[]
     *
     * @psalm-return list{int, int}
     */
    public static function getDimensions(array $exif): array
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
     * Get the Approximate Unique ID (AUID) from parameters.
     *
     * @param int $epoch the date taken as a unix timestamp (seconds)
     * @param int $size  the file size in bytes
     */
    public static function getAUID(int $epoch, int $size): string
    {
        return md5($epoch.$size);
    }

    /**
     * Get the Basename approximate Unique ID (BUID) from parameters.
     *
     * @param string $basename      the basename of the file
     * @param mixed  $imageUniqueID EXIF field
     * @param int    $size          the file size in bytes (fallback)
     */
    public static function getBUID(string $basename, mixed $imageUniqueID, int $size): string
    {
        $sfx = "size={$size}";
        if (null !== $imageUniqueID && \strlen((string) $imageUniqueID) >= 4) {
            $sfx = "iuid={$imageUniqueID}";
        }

        return md5($basename.$sfx);
    }

    /**
     * Get the list of MIME Types that are allowed to be edited.
     */
    public static function allowedEditMimetypes(): array
    {
        return array_diff(array_merge(Application::IMAGE_MIMES, Application::VIDEO_MIMES), self::FORBIDDEN_EDIT_MIMES);
    }

    /**
     * Set exif data using raw json.
     *
     * @param string               $path to local file
     * @param array<string, mixed> $data exif data
     *
     * @throws \Exception on failure
     */
    public static function setExif(string $path, array $data): void
    {
        $data['SourceFile'] = $path;
        $raw = json_encode([$data], JSON_UNESCAPED_UNICODE);
        $cmd = array_merge(self::getExiftool(), [
            '-overwrite_original_in_place', '-n',
            '-api', 'LargeFileSupport=1',
            '-json=-', $path,
        ]);
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
        proc_close($proc);
        if (str_contains($stdout, 'error')) {
            error_log("Exiftool error: {$stdout}");

            throw new \Exception('Could not set exif data: '.$stdout);
        }
    }

    /**
     * Set exif data using a raw array.
     *
     * @param array<string, mixed> $data exif data
     */
    public static function setFileExif(File $file, array $data): void
    {
        // Get path to local file so we can skip reading
        $path = $file->getStorage()->getLocalFile($file->getInternalPath());
        if (!$path) {
            throw new \Exception('Failed to get local file path');
        }

        // Set exif data
        self::setExif($path, $data);

        // Update remote file if not local
        if (!$file->getStorage()->isLocal()) {
            $file->putContent(fopen($path, 'r')); // closes the handler
        }

        // Touch the file, triggering a reprocess through the hook
        $file->touch();
    }

    public static function getBinaryExifProp(string $path, string $prop): string
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
            proc_close($proc);
        }
    }

    public static function getExifWithDuplicates(string $path): array
    {
        return self::getExifFromLocalPathWithSeparateProc($path, ['-U', '-G4']);
    }

    private static function getExiftool(): array
    {
        putenv('LANG=C'); // set perl lang to suppress warning

        return BinExt::getExiftool();
    }

    /**
     * Initialize static exiftool process for local reads.
     */
    private static function initializeStaticExiftoolProc(): void
    {
        self::closeStaticExiftoolProc();
        self::$staticPipes = [];
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
    private static function readOrTimeout($handle, int $timeout, ?string $delimiter = null): string
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

    private static function getExifFromLocalPathWithStaticProc(string $path): array
    {
        // This function should not be called if there is no static process
        if (!self::$staticPipes) {
            throw new \Error('[BUG] No static pipes found');
        }

        // Create arguments for exiftool
        $args = implode("\n", self::EXIFTOOL_ARGS);
        fwrite(self::$staticPipes[0], "{$path}\n{$args}\n-execute\n");
        fflush(self::$staticPipes[0]);

        // The output of exiftool's stay_open process ends with this token
        $readyToken = "\n{ready}\n";

        try {
            $buf = self::readOrTimeout(self::$staticPipes[1], self::EXIFTOOL_TIMEOUT, $readyToken);

            // The output buffer should always contain the ready token
            // (this is the point of readOrTimeout)
            $tokPos = strrpos($buf, $readyToken);
            if (false === $tokPos) {
                throw new \Error('[BUG] No ready token found in output buffer');
            }

            // Slice everything before the ready token
            $buf = substr($buf, 0, $tokPos);

            return self::processStdout($buf);
        } catch (\Exception) {
            error_log("ERROR: Exiftool may have crashed, restarting process [{$path}]");
            self::restartStaticExiftoolProc();

            throw new \Exception('Nothing to read from Exiftool');
        }
    }

    private static function getExifFromLocalPathWithSeparateProc(string $path, array $extraArgs = []): array
    {
        $pipes = [];
        $proc = proc_open(array_merge(self::getExiftool(), self::EXIFTOOL_ARGS, $extraArgs, [$path]), [
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
            proc_close($proc);
        }
    }

    /** Get json array from stdout of exiftool */
    private static function processStdout(string $stdout): array
    {
        $json = json_decode($stdout, true);
        if (!$json) {
            throw new \Exception('Could not read exif data');
        }

        return $json[0];
    }
}
