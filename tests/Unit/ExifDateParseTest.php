<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Exif;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ExifDateParseTest extends TestCase
{
    public function testStandardUtc(): void
    {
        $dt = Exif::parseExifDate([
            'DateTimeOriginal' => '2023:03:05 18:58:17',
        ]);
        self::assertSame('2023-03-05 18:58:17', $dt->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $dt->getTimezone()->getName());
        self::assertSame(1678042697, $dt->getTimestamp());
    }

    public function testFallbackToCreateDate(): void
    {
        $dt = Exif::parseExifDate([
            'CreateDate' => '2023:03:05 18:58:17',
        ]);
        self::assertSame('2023-03-05 18:58:17', $dt->format('Y-m-d H:i:s'));
    }

    public function testFormatWithoutSeconds(): void
    {
        $dt = Exif::parseExifDate([
            'DateTimeOriginal' => '2023:03:05 18:58',
        ]);
        self::assertSame('2023-03-05 18:58:00', $dt->format('Y-m-d H:i:s'));
    }

    public function testFormatWithSubseconds(): void
    {
        $dt = Exif::parseExifDate([
            'DateTimeOriginal' => '2023:03:05 18:58:17.500000',
        ]);
        self::assertSame('2023-03-05 18:58:17.500000', $dt->format('Y-m-d H:i:s.u'));
    }

    public function testFormatWithEmbeddedOffset(): void
    {
        $dt = Exif::parseExifDate([
            'DateTimeOriginal' => '2023:03:05 18:58:17+02:00',
        ]);
        self::assertSame('2023-03-05 18:58:17 +02:00', $dt->format('Y-m-d H:i:s P'));
        self::assertSame(7200, $dt->getOffset());
    }

    public function testTimezoneOffsetTimeOriginal(): void
    {
        $dt = Exif::parseExifDate([
            'DateTimeOriginal' => '2023:03:05 18:58:17',
            'OffsetTimeOriginal' => '+05:30',
        ]);
        self::assertSame('2023-03-05 18:58:17 +05:30', $dt->format('Y-m-d H:i:s P'));
        self::assertSame(19800, $dt->getOffset());
    }

    public function testTimezoneOffsetTime(): void
    {
        $dt = Exif::parseExifDate([
            'DateTimeOriginal' => '2023:03:05 18:58:17',
            'OffsetTime' => '-04:00',
        ]);
        self::assertSame('2023-03-05 18:58:17 -04:00', $dt->format('Y-m-d H:i:s P'));
        self::assertSame(-14400, $dt->getOffset());
    }

    public function testTimezoneLocationTzid(): void
    {
        $dt = Exif::parseExifDate([
            'DateTimeOriginal' => '2023:03:05 18:58:17',
            'LocationTZID' => 'America/New_York',
        ]);
        self::assertSame('America/New_York', $dt->getTimezone()->getName());
        self::assertSame('2023-03-05 18:58:17', $dt->format('Y-m-d H:i:s'));
    }

    public function testInvalidTimezoneFallbackToUtc(): void
    {
        $dt = Exif::parseExifDate([
            'DateTimeOriginal' => '2023:03:05 18:58:17',
            'OffsetTimeOriginal' => 'invalid-tz',
        ]);
        self::assertSame('2023-03-05 18:58:17', $dt->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $dt->getTimezone()->getName());
    }

    public function testVideoPrefersCreateDate(): void
    {
        $dt = Exif::parseExifDate([
            'MIMEType' => 'video/mp4',
            'DateTimeOriginal' => '2021:01:01 10:00:00',
            'CreateDate' => '2022:06:15 12:30:00',
        ]);
        self::assertSame('2022-06-15 12:30:00', $dt->format('Y-m-d H:i:s'));
    }

    public function testImagePrefersDateTimeOriginal(): void
    {
        $dt = Exif::parseExifDate([
            'MIMEType' => 'image/jpeg',
            'DateTimeOriginal' => '2021:01:01 10:00:00',
            'CreateDate' => '2022:06:15 12:30:00',
        ]);
        self::assertSame('2021-01-01 10:00:00', $dt->format('Y-m-d H:i:s'));
    }
}
