<?php

declare(strict_types=1);

namespace OCA\Memories;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Service\BinExt;
use OCA\Memories\Settings\SystemConfig;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;

class Exif
{
    private const FORBIDDEN_EDIT_MIMES = ['image/bmp', 'image/x-dcraw', 'video/MP2T']; // also update const.ts
    private const EXIFTOOL_TIMEOUT = 30000;
    private const EXIFTOOL_ARGS = ['-api', 'LargeFileSupport=1', '-a', '-json'];

    // Fields to search for dates in
    // Should also be set in ExifFields.php if you want them to show up in the metadata view
    private const DATE_FIELDS = [
        // Original date fields
        'SubSecDateTimeOriginal',
        'DateTimeOriginal',
        'SonyDateTime',

        // Create date fields
        'SubSecCreateDate',
        'CreationDate',
        'CreationDateValue',
        'CreateDate',
        'TrackCreateDate',
        'MediaCreateDate',
        'FileCreateDate',

        // ModifyDate fields
        'SubSecModifyDate',
        'ModifyDate',
        'TrackModifyDate',
        'MediaModifyDate',
        'FileModifyDate',
        ];

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
    public static function getExifFromFile(File $file, array $extraArgs = []): array
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

        // Get exif data
        $exif = self::getExifFromLocalPath($path, $extraArgs);

        // We need to remove blacklisted fields to prevent leaking info
        unset($exif['SourceFile'], $exif['FileName'], $exif['ExifToolVersion'], $exif['Directory'], $exif['FileSize'], $exif['FileAccessDate'], $exif['FileInodeChangeDate'], $exif['FilePermissions'], $exif['ThumbnailImage']);

        // Ignore zero dates
        foreach (self::DATE_FIELDS as $field) {
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
    public static function getExifFromLocalPath(string $path, array $extraArgs = []): array
    {
        if (null !== self::$staticProc) {
            self::ensureStaticExiftoolProc();

            return self::getExifFromLocalPathWithStaticProc($path, $extraArgs);
        }

        return self::getExifFromLocalPathWithSeparateProc($path, $extraArgs);
    }

    /**
     * Parse date from exif format and throw error if invalid.
     *
     * @param array<string, mixed> $exif
     */
    public static function parseExifDate(array $exif): \DateTime
    {
        // Collect all candidate date strings
        // Don't prioritize fields blindly because different cameras prioritize different fields
        // Instead we will be choosing the oldest valid date found in exif
        $candidates = [];
        foreach (self::DATE_FIELDS as $field) {
            if (isset($exif[$field]) && \is_string($exif[$field]) && $exif[$field] !== '') {
                $val = (string) $exif[$field];
                if (!str_starts_with($val, '0000:00:00')) {
                    $candidates[$field] = $val;
                }
            }
        }

        // Check if we have any candidates
        if (empty($candidates)) {
            throw new \Exception('No date found in exif');
        }

        // List of accepted parsing formats in priority order to try
        // By not using exiftool with -n we can let it get precise subseconds and timezone info for us when available
        // Prioritize formats with timezone and more precision first
        $formats = [
            'Y:m:d H:i:s.uP',
            'Y:m:d H:i:s.uO',
            'Y:m:d H:i:sP',
            'Y:m:d H:i:sO',
            'Y:m:d H:iP',
            'Y:m:d H:iO',
            'Y:m:d H:i:s.u',
            'Y:m:d H:i:s',
            'Y:m:d H:i',
        ];

        // Loop through candidates, compare them, and get the oldest valid date
        $exifDate = null;
        $parsedDate = null;
        $oldestTimestamp = null;
        $bestPrecision = -1;
        $winningField = null;
        foreach ($candidates as $field => $val) {
            $parse = null;
            $matchedFormat = null;
            
            // Replace trailing Z (Zulu) with +00:00 so formats using 'p' parse correctly.
            if (str_ends_with($val, 'Z')) {
                $val = preg_replace('/Z$/', '+00:00', $val);
            }

            // If timezone exists in a dedicated exif field get it
            $exifTz = null;
            try {
                $tzStr = $exif['OffsetTimeOriginal']
                    ?? $exif['OffsetTime']
                    ?? $exif['OffsetTimeDigitized']
                    ?? $exif['TimeZone']
                    ?? $exif['LocationTZID']
                    ?? throw new \Exception();

                /** @psalm-suppress ArgumentTypeCoercion */
                $exifTz = new \DateTimeZone((string) $tzStr);
            } catch (\Exception $e) {
                $exifTz = null;
            } catch (\ValueError $e) {
                $exifTz = null;
            }

            // Try to get a valid date with timezone from each candidate using accepted formats
            foreach ($formats as $format) {

                // If format contains timezone offset parse directly without messing with it
                // Set matchedFormat after any success in this loop so we have the format that succeeded
                if (strpos($format, 'O') !== false || strpos($format, 'P') !== false) {
                    $parse = \DateTime::createFromFormat($format, $val);
                    if ($parse instanceof \DateTime) {
                        // On success save date string timezone for use on formats lacking timezone if dedicated EXIF timezone doesn't exist
                        // This can happen if exiftool is able to find a timezone offset in a field that we didn't code for
                        if ($exifTz === null) {
                            // Only use string timezones we are sure are original ones because modification date timestamps may break oldest date logic
                            if ($field == 'SubSecDateTimeOriginal' || $field == 'SubSecCreateDate' || $field == 'CreationDate' || $field == 'CreationDateValue'){
                                $exifTz = $parse->getTimezone();
                            }
                        }
                        // Stop trying other formats on success
                        $matchedFormat = $format;
                        break;
                    }
                } else {
                    // If format lacks timezone offset try to correct it with offset from dedicated EXIF fields
                    // Dates that are UTC need to be shifted to the local timezone and dates that aren't need to have the timezone appended without shifting the clock time

                    // After examining many samples from different cameras it looks like modern PHOTOS usually have all their dates saved in local time
                    // For these we will append the EXIF timezone without shifting the clock time
                    // But when modern VIDEOS have dates that lack a timezone offset these dates are usually in UTC thanks to QuickTime
                    // So for these we will fully shift the clock time to the EXIF timezone while setting it

                    // This handling should cover the vast majority of cases correctly and should only fail when
                    // 1. The camera saved a photo with it's oldest date saved in UTC without timezone info
                    // 2. The camera saved a video with it's oldest date in local time without timezone info
                    // 3. The camera just saved dates completely wrong
                    if ($exifTz instanceof \DateTimeZone && preg_match('/^video\/\w+/i', (string) ($exif['MIMEType'] ?? null))) {
                        
                        // For videos shift time clock to timezone
                        $parse = \DateTime::createFromFormat($format, $val, new \DateTimeZone('UTC'));
                        if ($parse instanceof \DateTime) {
                            $parse->setTimezone($exifTz);
                            // Stop trying other formats on success
                            $matchedFormat = $format;
                            break;
                        }
                    } elseif ($exifTz instanceof \DateTimeZone) {
                        // For photos append timezone without shifting time clock
                        $parse = \DateTime::createFromFormat($format, $val, $exifTz);
                        // Stop trying other formats on success
                        if ($parse instanceof \DateTime) {
                            $matchedFormat = $format;
                            break;
                        }
                    } else {
                        // No timezone found, give up and assume UTC
                        // This only happens when there is absolutely no timezone info in the file across all fields
                        $parse = \DateTime::createFromFormat($format, $val, new \DateTimeZone('UTC'));
                        if ($parse instanceof \DateTime) {
                            $matchedFormat = $format;
                            break;
                        }
                    }
                }
            }

            // Timestamps are able to compare between different timezones accurately
            // So we use it to find the oldest date in candidates
            if ($parse instanceof \DateTime) {

                // Epoch timestamp
                $timestamp = $parse->getTimestamp();

                // Filter out January 1, 1904 12:00:00 AM UTC
                // Exiftool returns this as the date when QuickTimeUTC is set and
                // the date is set to 0000:00:00 00:00:00
                // Also filter out dates before 1800 A.D.
                if (-2082844800 !== $timestamp || $timestamp > -5364662400) {

                    // A more precise datetime will always look newer than a less precise datetime even if they are the same general timestamp
                    // In these scenarios we want to prefer the more precise datetime so we don't lose accuracy
                    // Determine precision level from the matched format
                    if ($matchedFormat) {
                        if (strpos($matchedFormat, 'u') !== false) {
                            $precision = 3;
                        } elseif (strpos($matchedFormat, 's') !== false) {
                            $precision = 2;
                        } else {
                            $precision = 1;
                        }
                    }
                    // Drop seconds and subseconds just for comparison, then subtract precision level in seconds to give them the right priority
                    $ot = (new \DateTime($parse->format('Y-m-d H:iO')))->modify("-{$precision} seconds")->getTimestamp();

                    // While looping through candidates we try to get the oldest datetime with the highest precision
                    if ($oldestTimestamp === null || $ot < $oldestTimestamp) {
                        $oldestTimestamp = $ot;
                        $exifDate = $val;
                        $winningField = $field;
                        $parsedDate = $parse;
                    }
                }
            }
        }

        // Check if we have a date
        if ($exifDate === null || !$parsedDate instanceof \DateTime) {
            throw new \Exception('No parsable date found in exif');
        }

        return $parsedDate;
    }

    /**
     * Get the date taken and timezone from either the file or exif data if available.
     *
     * @param array<string, mixed> $exif
     */
    public static function getDateTaken(File $file, array $exif): \DateTime
    {
        try {
            return self::parseExifDate($exif);
        } catch (\Exception $e) {
            error_log("parseExifDate failed: " . $e->getMessage());
        } catch (\ValueError $e) {
            error_log("parseExifDate ValueError: " . $e->getMessage());
        }

        // Fallback to FileModifyDate in UTC to remain consistent with parseExifDate fallback behavior
        try {
            $dt = new \DateTime('@'.$file->getMtime());
        } catch (\Throwable $e) {
            throw new \Error("FATAL: could not read file modification time: " . $e->getMessage());
        }
        
        return $dt;
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

        try {
            $output = Util::execSafe2($cmd, self::EXIFTOOL_TIMEOUT, $raw, true, true);
            $stdout = $output[0];
            $stderr = $output[1];
        } catch (\Exception $ex) {
            error_log("Timeout reading from exiftool: [{$path}]");

            throw $ex;
        }

        if (null !== $stderr && str_contains($stderr, 'Error')) {
            error_log("Exiftool error: {$stderr}");

            throw new \Exception('Could not set exif data: '.$stderr);
        }
        if (null === $stdout || str_contains($stdout, 'Error')) {
            $stdout = $stdout ?? $stderr ?? 'Error: Unknown cmd fail';
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

        // Dispatch NodeWrittenEvent to trigger processing by other apps
        try {
            $eventDispatcher = \OCP\Server::get(IEventDispatcher::class);
            $eventDispatcher->dispatchTyped(new NodeWrittenEvent($file));
        } catch (\Exception) {
            // Not our problem
        }

        // Touch the file, triggering a reprocess through the hook
        $file->touch();
    }

    public static function getBinaryExifProp(string $path, string $prop): string
    {
        $cmd = array_merge(self::getExiftool(), [$prop, '-n', '-b', $path]);

        try {
            return Util::execSafe($cmd, self::EXIFTOOL_TIMEOUT) ?? '';
        } catch (\Exception $ex) {
            error_log("Timeout reading from exiftool: [{$path}]");

            throw $ex;
        }
    }

    public static function getExifWithDuplicates(string $path): array
    {
        return self::getExifFromLocalPathWithSeparateProc($path, ['-U', '-G4']);
    }

    private static function getExiftool(): array
    {
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

    private static function getExifFromLocalPathWithStaticProc(string $path, array $extraArgs = []): array
    {
        // This function should not be called if there is no static process
        if (!self::$staticPipes) {
            throw new \Error('[BUG] No static pipes found');
        }

        // Create arguments for exiftool
        // Merge base args with extra args
        $allArgs = array_merge(self::EXIFTOOL_ARGS, $extraArgs);
        $args = implode("\n", $allArgs);
        fwrite(self::$staticPipes[0], "{$path}\n{$args}\n-execute\n");
        fflush(self::$staticPipes[0]);

        // The output of exiftool's stay_open process ends with this token
        $readyToken = "\n{ready}\n";

        try {
            $buf = Util::readOrTimeout(self::$staticPipes[1], self::EXIFTOOL_TIMEOUT, $readyToken);

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
        $cmd = array_merge(self::getExiftool(), self::EXIFTOOL_ARGS, $extraArgs, [$path]);

        try {
            $stdout = Util::execSafe($cmd, self::EXIFTOOL_TIMEOUT) ?? '';
        } catch (\Exception $ex) {
            error_log("Timeout reading from exiftool: [{$path}]");

            throw $ex;
        }

        return self::processStdout($stdout);
    }

    /** Get json array from stdout of exiftool */
    private static function processStdout(string $stdout): array
    {
        $json = json_decode($stdout, true);
        if (!$json) {
            throw new \Exception('Failed to parse exiftool output as JSON');
        }

        if (!\is_array($json) || !\count($json)) {
            throw new \Exception('Exiftool output is not an array with at least one element');
        }

        return $json[0];
    }
}
