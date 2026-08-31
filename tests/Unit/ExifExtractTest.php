<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Db\LivePhoto;
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
 * @covers \OCA\Memories\Db\LivePhoto
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

        // Date and Timezone (DST, -07:00)
        self::assertSame('2023:04:21 19:55:33', $exif['DateTimeOriginal'] ?? null);
        self::assertSame('-07:00', $exif['OffsetTimeOriginal'] ?? null);

        $dt = Exif::parseExifDate($exif);
        self::assertSame('2023-04-21 19:55:33 -07:00', $dt->format('Y-m-d H:i:s P'));
        self::assertSame(-25200, $dt->getOffset());
        self::assertSame(1682132133, $dt->getTimestamp());

        // Camera Info
        self::assertSame('samsung', $exif['Make'] ?? null);
        self::assertSame('SM-G991U1', $exif['Model'] ?? null);
        self::assertSame(2, $exif['FNumber'] ?? null);
        self::assertSame(0.25, $exif['ExposureTime'] ?? null);
        self::assertSame(5.9, $exif['FocalLength'] ?? null);
        self::assertSame(200, $exif['ISO'] ?? null);

        // Geolocation
        self::assertEqualsWithDelta(34.080404, (float) ($exif['GPSLatitude'] ?? 0), 0.0001);
        self::assertEqualsWithDelta(-118.245579, (float) ($exif['GPSLongitude'] ?? 0), 0.0001);
        self::assertSame(182, $exif['GPSAltitude'] ?? null);
    }

    public function testSamsungS2102(): void
    {
        // Samsung S21 HEIC photo in January (standard / non-DST time, -08:00)
        $path = __DIR__.'/../assets/samsung_s21_02.heic';
        self::assertFileExists($path);

        $exif = Exif::getExifFromLocalPath($path);
        self::assertSame('image/heic', $exif['MIMEType'] ?? null);

        // Date and Timezone (Non-DST, -08:00)
        self::assertSame('2023:01:18 21:18:39', $exif['DateTimeOriginal'] ?? null);
        self::assertSame('-08:00', $exif['OffsetTimeOriginal'] ?? null);

        $dt = Exif::parseExifDate($exif);
        self::assertSame('2023-01-18 21:18:39 -08:00', $dt->format('Y-m-d H:i:s P'));
        self::assertSame(-28800, $dt->getOffset());
        self::assertSame(1674105519, $dt->getTimestamp());
    }

    public function testAppleH264Boy(): void
    {
        $imagePath = __DIR__.'/../assets/apple_h264_boy.jpg';
        $videoPath = __DIR__.'/../assets/apple_h264_boy.mov';
        self::assertFileExists($imagePath);
        self::assertFileExists($videoPath);

        $imageExif = Exif::getExifFromLocalPath($imagePath);
        $videoExif = Exif::getExifFromLocalPath($videoPath);

        self::assertFalse(LivePhoto::isVideoPart($imageExif));
        self::assertTrue(LivePhoto::isVideoPart($videoExif));

        $photoLiveId = LivePhoto::getLivePhotoIdFromPath($imagePath, (int) filesize($imagePath), $imageExif);
        $videoLiveId = $videoExif['ContentIdentifier'] ?? null;

        self::assertSame('CC7B5EDE-BA2E-4DD5-85EB-50D0E8F94800', $photoLiveId);
        self::assertSame($photoLiveId, $videoLiveId);
    }

    public function testAppleH264Girl(): void
    {
        $imagePath = __DIR__.'/../assets/apple_h264_girl.jpg';
        $videoPath = __DIR__.'/../assets/apple_h264_girl.mov';
        self::assertFileExists($imagePath);
        self::assertFileExists($videoPath);

        $imageExif = Exif::getExifFromLocalPath($imagePath);
        $videoExif = Exif::getExifFromLocalPath($videoPath);

        self::assertFalse(LivePhoto::isVideoPart($imageExif));
        self::assertTrue(LivePhoto::isVideoPart($videoExif));

        $photoLiveId = LivePhoto::getLivePhotoIdFromPath($imagePath, (int) filesize($imagePath), $imageExif);
        $videoLiveId = $videoExif['ContentIdentifier'] ?? null;

        self::assertSame('C135D895-936B-4DF0-BB52-FC7AAF14F49B', $photoLiveId);
        self::assertSame($photoLiveId, $videoLiveId);
    }
}
