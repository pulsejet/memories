<?php

declare(strict_types=1);

namespace OCA\Memories;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Service\BinExt;
use OCA\Memories\Settings\SystemConfig;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;

final class Exif
{
    public const EXIF_KEY_IMAGE_WIDTH = 'ImageWidth';
    public const EXIF_KEY_IMAGE_HEIGHT = 'ImageHeight';
    public const EXIF_KEY_ROTATION = 'Rotation';
    public const EXIF_KEY_ORIENTATION = 'Orientation';

    private const FORBIDDEN_EDIT_MIMES = ['image/bmp', 'image/x-dcraw', 'video/MP2T']; // also update const.ts
    private const EXIFTOOL_TIMEOUT = 30000;
    private const EXIFTOOL_ARGS = ['-api', 'QuickTimeUTC=1', '-api', 'LargeFileSupport=1', '-n', '-json'];

    /** Opened instance of exiftool when running in command mode */
    /** @var ?resource */
    private $staticProc;

    /** @var ?resource[] */
    private $staticPipes;

    /** Disable usage of static process */
    private bool $noProc = false;

    public function __construct(
        private BinExt $binExt,
        private SystemConfig $systemConfig,
        private IEventDispatcher $eventDispatcher,
    ) {}

    public function closeStaticExiftoolProc(): void
    {
        try {
            // Close I/O pipes
            if ($this->staticPipes) {
                fclose($this->staticPipes[0]);
                fclose($this->staticPipes[1]);
                fclose($this->staticPipes[2]);
                $this->staticPipes = null;
            }

            // Close process
            if ($this->staticProc) {
                proc_terminate($this->staticProc);
                proc_close($this->staticProc);
                $this->staticProc = null;
            }
        } catch (\Exception $ex) {
        }
    }

    public function restartStaticExiftoolProc(): void
    {
        $this->closeStaticExiftoolProc();
        $this->ensureStaticExiftoolProc();
    }

    public function ensureStaticExiftoolProc(): void
    {
        if ($this->noProc) {
            return;
        }

        if (!$this->staticProc) {
            $this->initializeStaticExiftoolProc();
            usleep(500000); // wait if error

            if ($this->staticProc && !proc_get_status($this->staticProc)['running']) {
                error_log('WARN: Failed to create stay_open exiftool process');
                $this->noProc = true;
                $this->staticProc = null;
                $this->staticPipes = null;
            }

            return;
        }

        if (!proc_get_status($this->staticProc)['running']) {
            $this->staticProc = null;
            $this->staticPipes = null;
            $this->ensureStaticExiftoolProc();
        }
    }

    /**
     * Get exif data as a JSON object from a Nextcloud file.
     *
     * @return array<string, mixed>
     */
    public function getExifFromFile(File $file): array
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

        $exif = $this->getExifFromLocalPath($path);

        // We need to remove blacklisted fields to prevent leaking info
        unset($exif['SourceFile'], $exif['FileName'], $exif['ExifToolVersion'], $exif['Directory'], $exif['FileSize'], $exif['FileModifyDate'], $exif['FileAccessDate'], $exif['FileInodeChangeDate'], $exif['FilePermissions'], $exif['ThumbnailImage']);

        // Ignore zero dates
        $this->sanitizeDates($exif);

        return $exif;
    }

    /**
     * Get exif data as a JSON object from a local file path.
     *
     * @return array<string, mixed>
     */
    public function getExifFromLocalPath(string $path): array
    {
        if (null !== $this->staticProc) {
            $this->ensureStaticExiftoolProc();

            return $this->getExifFromLocalPathWithStaticProc($path);
        }

        return $this->getExifFromLocalPathWithSeparateProc($path);
    }

    /**
     * Parse date from exif format and throw error if invalid.
     *
     * @param array<string, mixed> $exif
     */
    public function parseExifDate(array $exif): \DateTime
    {
        // Ignore zero dates
        $this->sanitizeDates($exif);

        // Get date from exif
        $exifDate = $exif['SubSecDateTimeOriginal']
            ?? $exif['DateTimeOriginal']
            ?? $exif['SubSecCreateDate']
            ?? $exif['CreateDate']
            ?? null;

        // For videos, prefer ContentCreateDate for timezone (QuickTimeUTC=1)
        if (preg_match('/^video\/\w+/', (string) ($exif['MIMEType'] ?? null))) {
            $exifDate = $exif['ContentCreateDate']
                ?? $exif['CreationDate']
                ?? $exif['CreateDate']
                ?? $exifDate;
        }

        // Check if we have a date
        if (null === $exifDate || empty($exifDate) || !\is_string($exifDate)) {
            throw new \Exception('No date found in exif');
        }

        // Strip trailing DST/STD from exiftool H264 dates
        // https://github.com/pulsejet/memories/issues/1710
        $exifDate = preg_replace('/\s+(?:DST|STD)$/', '', $exifDate) ?? $exifDate;

        // Get timezone from exif
        try {
            $tzStr = $exif['OffsetTimeOriginal']
                ?? $exif['OffsetTime']
                ?? $exif['LocationTZID']
                ?? throw new \Exception();

            /** @psalm-suppress ArgumentTypeCoercion */
            $exifTz = new \DateTimeZone((string) $tzStr);
        } catch (\Exception) {
            $exifTz = null;
        }

        // Force UTC if no timezone found
        $parseTz = $exifTz ?? new \DateTimeZone('UTC');

        // https://github.com/pulsejet/memories/pull/397
        // https://github.com/pulsejet/memories/issues/485

        $formats = [
            'Y:m:d H:i:s.uO', // 2023:03:05 10:58:17.000Z
            'Y:m:d H:i:s.u', // 2023:03:05 10:58:17.000
            'Y:m:d H:i:sO', // 2023:03:05 10:58:17+05:00
            'Y:m:d H:i:s', // 2023:03:05 18:58:17
            'Y:m:d H:iO', // 2023:03:05 18:58+05:00
            'Y:m:d H:i', // 2023:03:05 18:58
        ];

        /** @var ?\DateTime $parsedDate */
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
    public function getDateTaken(File $file, array $exif): \DateTime
    {
        try {
            return $this->parseExifDate($exif);
        } catch (\Exception) {
        } catch (\ValueError) {
        }

        // Fall back to modification time
        $dt = new \DateTime('@'.$file->getMtime());

        // Set timezone to system timezone
        $tz = $this->systemConfig->get('default_timezone') ?: getenv('TZ') ?: date_default_timezone_get();

        try {
            $dt->setTimezone(new \DateTimeZone($tz));
        } catch (\Exception) {
            throw new \Error("FATAL: system timezone is invalid (TZ): {$tz}");
        }

        return $dt;
    }

    /**
     * Strip timezone, reinterpreting wall-clock time as UTC.
     */
    public function forgetTimezone(\DateTime $date): \DateTime
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
    public function getDimensions(array $exif): array
    {
        $width = $exif[self::EXIF_KEY_IMAGE_WIDTH] ?? 0;
        $height = $exif[self::EXIF_KEY_IMAGE_HEIGHT] ?? 0;

        // Sanity check the dimensions before using them
        if ($width <= 0 || $height <= 0 || $width > 100000 || $height > 100000) {
            return [0, 0];
        }

        // Check if image is rotated and we need to swap width and height
        $rotation = $exif[self::EXIF_KEY_ROTATION] ?? 0;
        $orientation = $exif[self::EXIF_KEY_ORIENTATION] ?? 0;
        if (\in_array($orientation, [5, 6, 7, 8], true) || \in_array($rotation, [90, 270], true)) {
            return [$height, $width];
        }

        return [$width, $height];
    }

    /**
     * Get the Basename approximate Unique ID (BUID) from parameters.
     *
     * @param string $basename      the basename of the file
     * @param mixed  $imageUniqueID EXIF field
     * @param int    $size          the file size in bytes (fallback)
     */
    public function getBUID(string $basename, mixed $imageUniqueID, int $size): string
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
    public function allowedEditMimetypes(): array
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
    public function setExif(string $path, array $data): void
    {
        $data['SourceFile'] = $path;
        $raw = json_encode([$data], JSON_UNESCAPED_UNICODE);
        $cmd = array_merge($this->getExiftool(), [
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
            $stdout ??= $stderr ?? 'Error: Unknown cmd fail';
            error_log("Exiftool error: {$stdout}");

            throw new \Exception('Could not set exif data: '.$stdout);
        }
    }

    /**
     * Set exif data using a raw array.
     *
     * @param array<string, mixed> $data exif data
     */
    public function setFileExif(File $file, array $data): void
    {
        // Get path to local file so we can skip reading
        $path = $file->getStorage()->getLocalFile($file->getInternalPath());
        if (!$path) {
            throw new \Exception('Failed to get local file path');
        }

        // Set exif data
        $this->setExif($path, $data);

        // Update remote file if not local
        if (!$file->getStorage()->isLocal()) {
            $file->putContent(fopen($path, 'r')); // closes the handler
        }

        // Dispatch NodeWrittenEvent to trigger processing by other apps
        try {
            $this->eventDispatcher->dispatchTyped(new NodeWrittenEvent($file));
        } catch (\Exception) {
            // Not our problem
        }

        // Touch the file, triggering a reprocess through the hook
        $file->touch();
    }

    public function getBinaryExifProp(string $path, string $prop): string
    {
        $cmd = array_merge($this->getExiftool(), [$prop, '-n', '-b', $path]);

        try {
            return Util::execSafe($cmd, self::EXIFTOOL_TIMEOUT) ?? '';
        } catch (\Exception $ex) {
            error_log("Timeout reading from exiftool: [{$path}]");

            throw $ex;
        }
    }

    private function getExiftool(): array
    {
        return $this->binExt->getExiftool();
    }

    /**
     * Initialize static exiftool process for local reads.
     */
    private function initializeStaticExiftoolProc(): void
    {
        $this->closeStaticExiftoolProc();
        $this->staticPipes = [];
        $proc = proc_open(array_merge($this->getExiftool(), ['-stay_open', 'true', '-@', '-']), [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $this->staticPipes);
        $this->staticProc = \is_resource($proc) ? $proc : null;
        stream_set_blocking($this->staticPipes[1], false);
    }

    private function getExifFromLocalPathWithStaticProc(string $path): array
    {
        // This function should not be called if there is no static process
        if (!$this->staticPipes) {
            throw new \Error('[BUG] No static pipes found');
        }

        // Create arguments for exiftool
        $args = implode("\n", self::EXIFTOOL_ARGS);
        fwrite($this->staticPipes[0], "{$path}\n{$args}\n-execute\n");
        fflush($this->staticPipes[0]);

        // The output of exiftool's stay_open process ends with this token
        $readyToken = "\n{ready}\n";

        try {
            $buf = Util::readOrTimeout($this->staticPipes[1], self::EXIFTOOL_TIMEOUT, $readyToken);

            // The output buffer should always contain the ready token
            // (this is the point of readOrTimeout)
            $tokPos = strrpos($buf, $readyToken);
            if (false === $tokPos) {
                throw new \Error('[BUG] No ready token found in output buffer');
            }

            // Slice everything before the ready token
            $buf = substr($buf, 0, $tokPos);

            return $this->processStdout($buf);
        } catch (\Exception) {
            error_log("ERROR: Exiftool may have crashed, restarting process [{$path}]");
            $this->restartStaticExiftoolProc();

            throw new \Exception('Nothing to read from Exiftool');
        }
    }

    private function getExifFromLocalPathWithSeparateProc(string $path, array $extraArgs = []): array
    {
        $cmd = array_merge($this->getExiftool(), self::EXIFTOOL_ARGS, $extraArgs, [$path]);

        try {
            $stdout = Util::execSafe($cmd, self::EXIFTOOL_TIMEOUT) ?? '';
        } catch (\Exception $ex) {
            error_log("Timeout reading from exiftool: [{$path}]");

            throw $ex;
        }

        return $this->processStdout($stdout);
    }

    /** Get json array from stdout of exiftool */
    private function processStdout(string $stdout): array
    {
        $json = json_decode($stdout, true);
        if (!$json) {
            throw new \Exception('Failed to parse exiftool output as JSON');
        }

        if (!\is_array($json) || !\count($json)) {
            throw new \Exception('Exiftool output is not an array with at least one element');
        }

        $exif = $json[0];
        if (empty($exif['Make'] ?? null) && !empty($exif['UserData_mak'] ?? null)) {
            $exif['Make'] = $exif['UserData_mak'];
        }
        if (empty($exif['Model'] ?? null) && !empty($exif['UserData_mod'] ?? null)) {
            $exif['Model'] = $exif['UserData_mod'];
        }

        return $exif;
    }

    private function sanitizeDates(array &$exif): void
    {
        $dateFields = [
            'DateTimeOriginal',
            'SubSecDateTimeOriginal',
            'ContentCreateDate',
            'CreateDate',
            'ModifyDate',
            'TrackCreateDate',
            'TrackModifyDate',
            'MediaCreateDate',
            'MediaModifyDate',
        ];
        foreach ($dateFields as $field) {
            if (!\array_key_exists($field, $exif)) {
                continue;
            }
            if (!\is_string($exif[$field]) || str_starts_with($exif[$field], '0000:00:00')) {
                unset($exif[$field]);
            }
        }
    }
}
