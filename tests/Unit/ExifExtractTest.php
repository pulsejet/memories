<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Exif;
use OCA\Memories\Service\BinExt;
use PHPUnit\Framework\TestCase;

/**
 * Tests EXIF extraction and date parsing on real media assets.
 *
 * Asset Naming Convention:
 * - Files in `tests/assets/` follow the format: `<camera_model>_<index>.<extension>`
 *   (e.g., `samsung_s21_01.jpg`, `apple_iphone14_01.heic`, `gopro_hero10_01.mp4`).
 * - This organizes test fixtures by the capturing camera or device make
 *   to ensure compatibility across different manufacturers and metadata formats.
 *
 * @internal
 *
 * @covers \OCA\Memories\Exif
 */
final class ExifExtractTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        BinExt::detectExiftool();
        Exif::ensureStaticExiftoolProc();
    }

    public static function tearDownAfterClass(): void
    {
        Exif::closeStaticExiftoolProc();
        parent::tearDownAfterClass();
    }

    public function testSamsungS2101(): void
    {
        $path = __DIR__.'/../assets/samsung_s21_01.jpg';
        self::assertFileExists($path);

        $exif = Exif::getExifFromLocalPath($path);
        self::assertSame('image/jpeg', $exif['MIMEType'] ?? null);
        self::assertSame('2023:04:21 19:55:33', $exif['DateTimeOriginal'] ?? null);
        self::assertSame('-07:00', $exif['OffsetTimeOriginal'] ?? null);

        $dt = Exif::parseExifDate($exif);
        self::assertSame('2023-04-21 19:55:33', $dt->format('Y-m-d H:i:s'));
        self::assertSame('2023-04-21 19:55:33 -07:00', $dt->format('Y-m-d H:i:s P'));
        self::assertSame(-25200, $dt->getOffset());
        self::assertSame(1682132133, $dt->getTimestamp());
    }
}
