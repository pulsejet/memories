<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Db\LivePhoto;
use OCA\Memories\Exif;
use OCA\Memories\Service\BinExt;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ExtractResult
{
    /**
     * @param array<string, mixed> $exif
     */
    public function __construct(
        public readonly string $path,
        public readonly array $exif,
        public readonly string $livePhotoId,
    ) {}
}

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
        $res = $this->extract('samsung_s21_01.jpg');
        self::assertSame('image/jpeg', $res->exif['MIMEType'] ?? null);

        // Date and Timezone (DST, -07:00)
        self::assertSame('2023:04:21 19:55:33', $res->exif['DateTimeOriginal'] ?? null);
        self::assertSame('-07:00', $res->exif['OffsetTimeOriginal'] ?? null);

        $dt = Exif::parseExifDate($res->exif);
        self::assertSame('2023-04-21 19:55:33 -07:00', $dt->format('Y-m-d H:i:s P'));
        self::assertSame(-25200, $dt->getOffset());
        self::assertSame(1682132133, $dt->getTimestamp());

        // Camera Info
        self::assertSame('samsung', $res->exif['Make'] ?? null);
        self::assertSame('SM-G991U1', $res->exif['Model'] ?? null);
        self::assertSame(2, $res->exif['FNumber'] ?? null);
        self::assertSame(0.25, $res->exif['ExposureTime'] ?? null);
        self::assertSame(5.9, $res->exif['FocalLength'] ?? null);
        self::assertSame(200, $res->exif['ISO'] ?? null);

        // Geolocation
        self::assertEqualsWithDelta(34.080404, (float) ($res->exif['GPSLatitude'] ?? 0), 0.0001);
        self::assertEqualsWithDelta(-118.245579, (float) ($res->exif['GPSLongitude'] ?? 0), 0.0001);
        self::assertSame(182, $res->exif['GPSAltitude'] ?? null);
    }

    public function testSamsungS2102(): void
    {
        // Samsung S21 HEIC photo in January (standard / non-DST time, -08:00)
        $res = $this->extract('samsung_s21_02.heic');
        self::assertSame('image/heic', $res->exif['MIMEType'] ?? null);

        // Date and Timezone (Non-DST, -08:00)
        self::assertSame('2023:01:18 21:18:39', $res->exif['DateTimeOriginal'] ?? null);
        self::assertSame('-08:00', $res->exif['OffsetTimeOriginal'] ?? null);

        $dt = Exif::parseExifDate($res->exif);
        self::assertSame('2023-01-18 21:18:39 -08:00', $dt->format('Y-m-d H:i:s P'));
        self::assertSame(-28800, $dt->getOffset());
        self::assertSame(1674105519, $dt->getTimestamp());
    }

    public function testSamsungS2103(): void
    {
        // Samsung S21 MP4 video
        $res = $this->extract('samsung_s21_03.mp4');
        self::assertSame('video/mp4', $res->exif['MIMEType'] ?? null);
        self::assertFalse(LivePhoto::isVideoPart($res->exif));

        // Video dimensions and rotation
        self::assertSame(1920, $res->exif['ImageWidth'] ?? null);
        self::assertSame(1080, $res->exif['ImageHeight'] ?? null);
        self::assertSame(90, $res->exif['Rotation'] ?? null);

        // Geolocation (Rockville, MD)
        self::assertEqualsWithDelta(39.0837, (float) ($res->exif['GPSLatitude'] ?? 0), 0.0001);
        self::assertEqualsWithDelta(-77.1472, (float) ($res->exif['GPSLongitude'] ?? 0), 0.0001);

        // The MP4 metadata does not contain an explicit timezone offset.
        // Instead, the geolocation is used to resolve the timezone for the capture location.
        // In this case, LocationTZID is set to 'America/New_York'.
        $exifWithTz = $res->exif;
        $exifWithTz['LocationTZID'] = 'America/New_York';

        $dt = Exif::parseExifDate($exifWithTz);
        self::assertSame('2023-03-05 13:58:17 -05:00', $dt->format('Y-m-d H:i:s P'));
        self::assertSame(-18000, $dt->getOffset());
        self::assertSame(1678042697, $dt->getTimestamp());

        // Hypothetically, if this video had been captured in Central Time, the resolved timezone
        // would be 'America/Chicago' (UTC-6), giving the local capture time as ~12:58 PM.
        $exifCentral = $res->exif;
        $exifCentral['LocationTZID'] = 'America/Chicago';

        $dtCentral = Exif::parseExifDate($exifCentral);
        self::assertSame('2023-03-05 12:58:17 -06:00', $dtCentral->format('Y-m-d H:i:s P'));
        self::assertSame(-21600, $dtCentral->getOffset());
        self::assertSame(1678042697, $dtCentral->getTimestamp());
    }

    public function testSamsungS2401(): void
    {
        $res = $this->extract('samsung_s24_01.jpg');
        self::assertSame('image/jpeg', $res->exif['MIMEType'] ?? null);
        self::assertSame('self__exifbin=EmbeddedVideoFile', $res->livePhotoId);

        $video = Exif::getBinaryExifProp($res->path, '-EmbeddedVideoFile');
        self::assertSame('ftyp', substr($video, 4, 4));

        $dt = Exif::parseExifDate($res->exif);
        self::assertSame('2024-08-09 21:17:01 +02:00', $dt->format('Y-m-d H:i:s P'));
        self::assertSame(7200, $dt->getOffset());
        self::assertSame(1723231021, $dt->getTimestamp());
    }

    public function testSamsungS2501(): void
    {
        $res = $this->extract('samsung_s25_01.jpg');
        self::assertSame('image/jpeg', $res->exif['MIMEType'] ?? null);
        self::assertSame('self__exifbin=EmbeddedVideoFile', $res->livePhotoId);

        $video = Exif::getBinaryExifProp($res->path, '-EmbeddedVideoFile');
        self::assertSame('ftyp', substr($video, 4, 4));

        // Date and Timezone (DST, -04:00)
        $dt = Exif::parseExifDate($res->exif);
        self::assertSame('2025-04-03 09:11:42 -04:00', $dt->format('Y-m-d H:i:s P'));
        self::assertSame(-14400, $dt->getOffset());
        self::assertSame(1743685902, $dt->getTimestamp());

        // Camera Info
        self::assertSame('samsung', $res->exif['Make'] ?? null);
        self::assertSame('Galaxy S25+', $res->exif['Model'] ?? null);
    }

    public function testAppleH264Boy01(): void
    {
        $image = $this->extract('apple_h264_boy_01.jpg');
        $video = $this->extract('apple_h264_boy_01.mov');

        self::assertFalse(LivePhoto::isVideoPart($image->exif));
        self::assertTrue(LivePhoto::isVideoPart($video->exif));

        $videoLiveId = $video->exif['ContentIdentifier'] ?? null;
        self::assertSame('CC7B5EDE-BA2E-4DD5-85EB-50D0E8F94800', $image->livePhotoId);
        self::assertSame($image->livePhotoId, $videoLiveId);
    }

    public function testAppleH264Girl01(): void
    {
        $image = $this->extract('apple_h264_girl_01.jpg');
        $video = $this->extract('apple_h264_girl_01.mov');

        self::assertFalse(LivePhoto::isVideoPart($image->exif));
        self::assertTrue(LivePhoto::isVideoPart($video->exif));

        $videoLiveId = $video->exif['ContentIdentifier'] ?? null;
        self::assertSame('C135D895-936B-4DF0-BB52-FC7AAF14F49B', $image->livePhotoId);
        self::assertSame($image->livePhotoId, $videoLiveId);
    }

    public function testGoogleMotion01(): void
    {
        $res = $this->extract('google_motion_01.jpg');
        self::assertSame('image/jpeg', $res->exif['MIMEType'] ?? null);
        self::assertSame('self__exifbin=MotionPhotoVideo', $res->livePhotoId);

        $video = Exif::getBinaryExifProp($res->path, '-MotionPhotoVideo');
        self::assertSame('ftyp', substr($video, 4, 4));

        $dt = Exif::parseExifDate($res->exif);
        self::assertSame('2023-02-10 18:12:21 +01:00', $dt->format('Y-m-d H:i:s P'));
    }

    public function testGoogleMotion02(): void
    {
        $res = $this->extract('google_motion_02.jpg');
        self::assertSame('image/jpeg', $res->exif['MIMEType'] ?? null);
        self::assertSame('self__exifbin=MotionPhotoVideo', $res->livePhotoId);

        $video = Exif::getBinaryExifProp($res->path, '-MotionPhotoVideo');
        self::assertSame('ftyp', substr($video, 4, 4));

        $dt = Exif::parseExifDate($res->exif);
        self::assertSame('2021-08-30 10:37:47 +05:30', $dt->format('Y-m-d H:i:s P'));
    }

    public function testGoogleMotion03(): void
    {
        $res = $this->extract('google_motion_03.jpg');
        self::assertSame('image/jpeg', $res->exif['MIMEType'] ?? null);
        self::assertSame('self__exifbin=MotionPhotoVideo', $res->livePhotoId);

        $video = Exif::getBinaryExifProp($res->path, '-MotionPhotoVideo');
        self::assertSame('ftyp', substr($video, 4, 4));

        $dt = Exif::parseExifDate($res->exif);
        self::assertSame('2022-07-07 20:27:03 +02:00', $dt->format('Y-m-d H:i:s P'));
    }

    public function testGoogleMotion04Hevc(): void
    {
        $res = $this->extract('google_motion_04_hevc.jpg');
        self::assertSame('image/jpeg', $res->exif['MIMEType'] ?? null);
        self::assertSame('self__exifbin=MotionPhotoVideo', $res->livePhotoId);

        $video = Exif::getBinaryExifProp($res->path, '-MotionPhotoVideo');
        self::assertSame('ftyp', substr($video, 4, 4));
    }

    public function testGoogleMotion05(): void
    {
        $res = $this->extract('google_motion_05.jpg');
        self::assertSame('image/jpeg', $res->exif['MIMEType'] ?? null);
        self::assertSame('self__exifbin=MotionPhotoVideo', $res->livePhotoId);

        $video = Exif::getBinaryExifProp($res->path, '-MotionPhotoVideo');
        self::assertSame('ftyp', substr($video, 4, 4));

        $dt = Exif::parseExifDate($res->exif);
        self::assertSame('2022-12-03 18:48:32 +02:00', $dt->format('Y-m-d H:i:s P'));
    }

    public function testGoogleMvimg01(): void
    {
        $res = $this->extract('google_mvimg_01.jpg');
        self::assertSame('image/jpeg', $res->exif['MIMEType'] ?? null);
        self::assertSame('self__traileroffset=4347622', $res->livePhotoId);

        $video = file_get_contents($res->path, false, null, 4347622);
        self::assertSame('ftyp', substr($video, 4, 4));

        $dt = Exif::parseExifDate($res->exif);
        self::assertSame('2023-03-10 18:39:04 +00:00', $dt->format('Y-m-d H:i:s P'));
    }

    public function testSamsungMotion01(): void
    {
        $res = $this->extract('samsung_motion_01.jpg');
        self::assertSame('image/jpeg', $res->exif['MIMEType'] ?? null);
        self::assertSame('self__traileroffset=3534847', $res->livePhotoId);

        $video = file_get_contents($res->path, false, null, 3534847);
        self::assertSame('ftyp', substr($video, 4, 4));

        $dt = Exif::parseExifDate($res->exif);
        self::assertSame('2020-03-08 00:51:56 +00:00', $dt->format('Y-m-d H:i:s P'));
    }

    public function testSamsungMotionS2101Heic(): void
    {
        $res = $this->extract('samsung_motion_s21_01.heic');
        self::assertSame('image/heic', $res->exif['MIMEType'] ?? null);
        self::assertSame('self__exifbin=MotionPhotoVideo', $res->livePhotoId);

        $video = Exif::getBinaryExifProp($res->path, '-MotionPhotoVideo');
        self::assertSame('ftyp', substr($video, 4, 4));

        $dt = Exif::parseExifDate($res->exif);
        self::assertSame('2023-10-04 22:53:36 -07:00', $dt->format('Y-m-d H:i:s P'));
    }

    public function testSamsungMotionS2101Jpg(): void
    {
        $res = $this->extract('samsung_motion_s21_01.jpg');
        self::assertSame('image/jpeg', $res->exif['MIMEType'] ?? null);
        self::assertSame('self__exifbin=MotionPhotoVideo', $res->livePhotoId);

        $video = Exif::getBinaryExifProp($res->path, '-MotionPhotoVideo');
        self::assertSame('ftyp', substr($video, 4, 4));

        $dt = Exif::parseExifDate($res->exif);
        self::assertSame('2023-10-04 22:55:33 -07:00', $dt->format('Y-m-d H:i:s P'));
    }

    private function extract(string $filename): ExtractResult
    {
        $path = __DIR__.'/../assets/'.$filename;
        self::assertFileExists($path);

        $exif = Exif::getExifFromLocalPath($path);
        $livePhotoId = LivePhoto::getLivePhotoIdFromPath($path, (int) filesize($path), $exif);

        return new ExtractResult($path, $exif, $livePhotoId);
    }
}
