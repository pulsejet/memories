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

        // Even if LocationTZID is hypothetically set to 'America/Chicago',
        // the explicit OffsetTimeOriginal takes precedence and is not affected.
        $exifWithTz = $res->exif;
        $exifWithTz['LocationTZID'] = 'America/Chicago';

        $dtWithTz = Exif::parseExifDate($exifWithTz);
        self::assertSame('2023-01-18 21:18:39 -08:00', $dtWithTz->format('Y-m-d H:i:s P'));
        self::assertSame(-28800, $dtWithTz->getOffset());
        self::assertSame(1674105519, $dtWithTz->getTimestamp());
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

    public function testSamsungM2101(): void
    {
        // Samsung Galaxy M21 photo taken at Tokyo Haneda Airport
        $res = $this->extract('samsung_m21_01.jpg');
        self::assertSame('image/jpeg', $res->exif['MIMEType'] ?? null);

        // Camera Info
        self::assertSame('samsung', $res->exif['Make'] ?? null);
        self::assertSame('SM-M215F', $res->exif['Model'] ?? null);
        self::assertSame(2, $res->exif['FNumber'] ?? null);
        self::assertSame(4.6, $res->exif['FocalLength'] ?? null);
        self::assertSame(20, $res->exif['ISO'] ?? null);

        // Geolocation (Tokyo Haneda Airport)
        self::assertEqualsWithDelta(35.545555, (float) ($res->exif['GPSLatitude'] ?? 0), 0.0001);
        self::assertEqualsWithDelta(139.769361, (float) ($res->exif['GPSLongitude'] ?? 0), 0.0001);

        // Date and Timezone (JST, +09:00)
        self::assertSame('2021:03:26 15:53:38', $res->exif['DateTimeOriginal'] ?? null);
        self::assertSame('+09:00', $res->exif['OffsetTimeOriginal'] ?? null);

        $dt = Exif::parseExifDate($res->exif);
        self::assertSame('2021-03-26 15:53:38 +09:00', $dt->format('Y-m-d H:i:s P'));
        self::assertSame(32400, $dt->getOffset());
        self::assertSame(1616741618, $dt->getTimestamp());
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

    public function testAppleIphone01(): void
    {
        // Apple iPhone 12 mini Live Photo pair
        $image = $this->extract('apple_iphone_01.jpg');
        $video = $this->extract('apple_iphone_01.mov');

        self::assertFalse(LivePhoto::isVideoPart($image->exif));
        self::assertTrue(LivePhoto::isVideoPart($video->exif));

        $videoLiveId = $video->exif['ContentIdentifier'] ?? null;
        self::assertSame('021842E6-D17A-4C62-BC13-B2521961DF0B', $image->livePhotoId);
        self::assertSame($image->livePhotoId, $videoLiveId);

        // Date and Timezone (EST, -05:00)
        self::assertSame('2022:11:21 16:49:32', $image->exif['DateTimeOriginal'] ?? null);
        self::assertSame('-05:00', $image->exif['OffsetTimeOriginal'] ?? null);

        $dt = Exif::parseExifDate($image->exif);
        self::assertSame('2022-11-21 16:49:32 -05:00', $dt->format('Y-m-d H:i:s P'));
        self::assertSame(-18000, $dt->getOffset());
        self::assertSame(1669067372, $dt->getTimestamp());

        // Camera Info
        self::assertSame('Apple', $image->exif['Make'] ?? null);
        self::assertSame('iPhone 12 mini', $image->exif['Model'] ?? null);
        self::assertSame(1.6, $image->exif['FNumber'] ?? null);
        self::assertSame(4.2, $image->exif['FocalLength'] ?? null);
        self::assertSame(125, $image->exif['ISO'] ?? null);

        // Geolocation
        self::assertEqualsWithDelta(42.421803, (float) ($image->exif['GPSLatitude'] ?? 0), 0.0001);
        self::assertEqualsWithDelta(-75.591911, (float) ($image->exif['GPSLongitude'] ?? 0), 0.0001);
        self::assertEqualsWithDelta(453.0998, (float) ($image->exif['GPSAltitude'] ?? 0), 0.0001);
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

    public function testSamsungS20Fe01(): void
    {
        // Samsung Galaxy S20 FE 5G HEIC Motion Photo
        $res = $this->extract('samsung_s20_fe_01.heic');
        self::assertSame('image/heic', $res->exif['MIMEType'] ?? null);
        self::assertFalse(LivePhoto::isVideoPart($res->exif));
        self::assertSame('self__exifbin=MotionPhotoVideo', $res->livePhotoId);

        // Binary video extraction
        $video = Exif::getBinaryExifProp($res->path, '-MotionPhotoVideo');
        self::assertSame('ftyp', substr($video, 4, 4));

        // Date and Timezone (+02:00)
        self::assertSame('2022:04:23 08:59:35', $res->exif['DateTimeOriginal'] ?? null);
        self::assertSame('+02:00', $res->exif['OffsetTimeOriginal'] ?? null);

        $dt = Exif::parseExifDate($res->exif);
        self::assertSame('2022-04-23 08:59:35 +02:00', $dt->format('Y-m-d H:i:s P'));
        self::assertSame(7200, $dt->getOffset());
        self::assertSame(1650697175, $dt->getTimestamp());

        // Camera Info
        self::assertSame('samsung', $res->exif['Make'] ?? null);
        self::assertSame('SM-G781B', $res->exif['Model'] ?? null);
        self::assertSame(1.8, $res->exif['FNumber'] ?? null);
        self::assertSame(5.4, $res->exif['FocalLength'] ?? null);
        self::assertSame(40, $res->exif['ISO'] ?? null);

        // Geolocation
        self::assertEqualsWithDelta(51.433469, (float) ($res->exif['GPSLatitude'] ?? 0), 0.0001);
        self::assertEqualsWithDelta(12.110732, (float) ($res->exif['GPSLongitude'] ?? 0), 0.0001);
    }

    public function testUnknown01Video(): void
    {
        // MP4 video taken in Berlin, Germany
        $res = $this->extract('unknown_01.mp4');
        self::assertSame('video/mp4', $res->exif['MIMEType'] ?? null);
        self::assertFalse(LivePhoto::isVideoPart($res->exif));
        self::assertSame('', $res->livePhotoId);

        // Geolocation (Berlin, Germany)
        self::assertEqualsWithDelta(52.517037, (float) ($res->exif['GPSLatitude'] ?? 0), 0.0001);
        self::assertEqualsWithDelta(13.38886, (float) ($res->exif['GPSLongitude'] ?? 0), 0.0001);

        // The MP4 container stores date in UTC but contains no explicit timezone offset.
        // When parsed without a timezone, ExifTool converts the UTC date using the local machine's
        // timezone, giving the correct epoch timestamp but with the test runner's system timezone.
        $dtNoTz = Exif::parseExifDate($res->exif);
        self::assertSame(1678026114, $dtNoTz->getTimestamp());

        // When the timezone is resolved from the geolocation (Europe/Berlin, UTC+1),
        // both the local capture date and timezone offset are correctly represented.
        $exifWithTz = $res->exif;
        $exifWithTz['LocationTZID'] = 'Europe/Berlin';

        $dt = Exif::parseExifDate($exifWithTz);
        self::assertSame('2023-03-05 15:21:54 +01:00', $dt->format('Y-m-d H:i:s P'));
        self::assertSame(3600, $dt->getOffset());
        self::assertSame(1678026114, $dt->getTimestamp());
    }

    public function testUnknown01Image(): void
    {
        // JPEG photo in Berlin, Germany (Samsung S9+ / SM-G965F)
        // taken ~7 seconds before unknown_01.mp4
        $res = $this->extract('unknown_01.jpg');
        self::assertSame('image/jpeg', $res->exif['MIMEType'] ?? null);
        self::assertFalse(LivePhoto::isVideoPart($res->exif));
        self::assertSame('', $res->livePhotoId);

        // Camera Info
        self::assertSame('samsung', $res->exif['Make'] ?? null);
        self::assertSame('SM-G965F', $res->exif['Model'] ?? null);
        self::assertSame(2.4, $res->exif['FNumber'] ?? null);
        self::assertSame(4.3, $res->exif['FocalLength'] ?? null);
        self::assertSame(50, $res->exif['ISO'] ?? null);

        // In this photo, DateTimeOriginal contains the local time string ("2023:03:05 15:21:47")
        // without any timezone offset. When parsed without a timezone, it defaults to UTC (+00:00).
        // While the formatted time string matches the local time, the resulting epoch timestamp is wrong.
        $dtNoTz = Exif::parseExifDate($res->exif);
        self::assertSame('2023-03-05 15:21:47 +00:00', $dtNoTz->format('Y-m-d H:i:s P'));
        self::assertSame(0, $dtNoTz->getOffset());
        self::assertSame(1678029707, $dtNoTz->getTimestamp()); // wrong epoch

        // When LocationTZID is set to the capture location's timezone ('Europe/Berlin', UTC+1),
        // the date is properly interpreted in Berlin time, producing the correct epoch timestamp.
        $exifWithTz = $res->exif;
        $exifWithTz['LocationTZID'] = 'Europe/Berlin';

        $dt = Exif::parseExifDate($exifWithTz);
        self::assertSame('2023-03-05 15:21:47 +01:00', $dt->format('Y-m-d H:i:s P'));
        self::assertSame(3600, $dt->getOffset());
        self::assertSame(1678026107, $dt->getTimestamp());
    }

    public function testSonyE566301(): void
    {
        // Sony Xperia M5 photo with no coordinates and local time only (no timezone info).
        // It reports the local capture time: Tue, Mar 27, 2018 9:43 AM.
        $res = $this->extract('sony_e5663_01.jpg');
        self::assertSame('image/jpeg', $res->exif['MIMEType'] ?? null);
        self::assertFalse(LivePhoto::isVideoPart($res->exif));
        self::assertSame('', $res->livePhotoId);

        // Camera Info
        self::assertSame('Sony', $res->exif['Make'] ?? null);
        self::assertSame('E5663', $res->exif['Model'] ?? null);
        self::assertSame(2.2, $res->exif['FNumber'] ?? null);
        self::assertSame(4.6, $res->exif['FocalLength'] ?? null);
        self::assertSame(1919, $res->exif['ISO'] ?? null);

        // Date and Time (defaults to UTC without explicit timezone or coordinates)
        self::assertSame('2018:03:27 09:43:23', $res->exif['DateTimeOriginal'] ?? null);
        self::assertArrayNotHasKey('OffsetTimeOriginal', $res->exif);
        self::assertArrayNotHasKey('GPSLatitude', $res->exif);
        self::assertArrayNotHasKey('GPSLongitude', $res->exif);

        $dt = Exif::parseExifDate($res->exif);
        self::assertSame('Tue, Mar 27, 2018 9:43 AM', $dt->format('D, M j, Y g:i A'));
        self::assertSame('2018-03-27 09:43:23 +00:00', $dt->format('Y-m-d H:i:s P'));
        self::assertSame(0, $dt->getOffset());
        self::assertSame(1522143803, $dt->getTimestamp());
    }

    public function testSetExif(): void
    {
        // Copy non-motion Samsung S21 photo to a temporary file
        $tmpFile = tempnam(sys_get_temp_dir(), 'memories_exif_test_').'.jpg';
        copy(__DIR__.'/../assets/samsung_s21_01.jpg', $tmpFile);

        try {
            // Update geolocation, date, title, description, label, artist, copyright, rating
            $dataToSet = [
                'DateTimeOriginal' => '2024:06:15 14:30:00',
                'OffsetTimeOriginal' => '+02:00',
                'GPSLatitude' => 48.858844,
                'GPSLongitude' => 2.294351,
                'GPSLatitudeRef' => 'N',
                'GPSLongitudeRef' => 'E',
                'GPSAltitude' => 35,
                'Description' => 'Vacation photo at Eiffel Tower',
                'Label' => 'Selected',
                'Title' => 'Eiffel Tower Visit',
                'Artist' => 'Memories Tester',
                'Copyright' => 'Copyright 2024',
                'Rating' => 5,
            ];

            Exif::setExif($tmpFile, $dataToSet);

            // Re-read EXIF from updated file
            $exif = Exif::getExifFromLocalPath($tmpFile);

            // Verify updated date & timezone
            self::assertSame('2024:06:15 14:30:00', $exif['DateTimeOriginal'] ?? null);
            self::assertSame('+02:00', $exif['OffsetTimeOriginal'] ?? null);
            $dt = Exif::parseExifDate($exif);
            self::assertSame('2024-06-15 14:30:00 +02:00', $dt->format('Y-m-d H:i:s P'));
            self::assertSame(7200, $dt->getOffset());
            self::assertSame(1718454600, $dt->getTimestamp());

            // Verify updated geolocation
            self::assertEqualsWithDelta(48.858844, (float) ($exif['GPSLatitude'] ?? 0), 0.0001);
            self::assertEqualsWithDelta(2.294351, (float) ($exif['GPSLongitude'] ?? 0), 0.0001);
            self::assertSame(35, $exif['GPSAltitude'] ?? null);

            // Verify updated metadata fields
            self::assertSame('Vacation photo at Eiffel Tower', $exif['Description'] ?? null);
            self::assertSame('Selected', $exif['Label'] ?? null);
            self::assertSame('Eiffel Tower Visit', $exif['Title'] ?? null);
            self::assertSame('Memories Tester', $exif['Artist'] ?? null);
            self::assertSame('Copyright 2024', $exif['Copyright'] ?? null);
            self::assertSame(5, $exif['Rating'] ?? null);

            // Verify immutable camera info remains unchanged
            self::assertSame('samsung', $exif['Make'] ?? null);
            self::assertSame('SM-G991U1', $exif['Model'] ?? null);
            self::assertSame(2, $exif['FNumber'] ?? null);
            self::assertSame(5.9, $exif['FocalLength'] ?? null);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
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
