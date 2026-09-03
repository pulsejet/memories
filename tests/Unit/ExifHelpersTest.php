<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Exif;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @covers \OCA\Memories\Exif
 */
final class ExifHelpersTest extends TestCase
{
    public function testGetAuid(): void
    {
        self::assertSame(md5('167804269712345'), Exif::getAUID(1678042697, 12345));
        self::assertNotSame(Exif::getAUID(1, 2), Exif::getAUID(1, 3));
    }

    public function testGetBuid(): void
    {
        $withId = Exif::getBUID('IMG_001.jpg', 'ABCDEF123456', 999);
        self::assertSame(md5('IMG_001.jpgiuid=ABCDEF123456'), $withId);

        // Short IDs (< 4 chars) fall back to size so unstable IDs are ignored
        self::assertSame(md5('IMG_001.jpgsize=999'), Exif::getBUID('IMG_001.jpg', 'ab', 999));
        self::assertSame(md5('IMG_001.jpgsize=999'), Exif::getBUID('IMG_001.jpg', null, 999));
    }

    public function testForgetTimezone(): void
    {
        $dt = Exif::forgetTimezone(new \DateTime('2023-03-05 18:58:17+05:30'));
        self::assertSame('2023-03-05 18:58:17', $dt->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $dt->getTimezone()->getName());
    }

    public function testGetDimensions(): void
    {
        self::assertSame([4000, 3000], Exif::getDimensions([
            Exif::EXIF_KEY_IMAGE_WIDTH => 4000,
            Exif::EXIF_KEY_IMAGE_HEIGHT => 3000,
        ]));
        self::assertSame([3000, 4000], Exif::getDimensions([
            Exif::EXIF_KEY_IMAGE_WIDTH => 4000,
            Exif::EXIF_KEY_IMAGE_HEIGHT => 3000,
            Exif::EXIF_KEY_ROTATION => 90,
        ]));
        self::assertSame([3000, 4000], Exif::getDimensions([
            Exif::EXIF_KEY_IMAGE_WIDTH => 4000,
            Exif::EXIF_KEY_IMAGE_HEIGHT => 3000,
            Exif::EXIF_KEY_ROTATION => 270,
        ]));
        self::assertSame([4000, 3000], Exif::getDimensions([
            Exif::EXIF_KEY_IMAGE_WIDTH => 4000,
            Exif::EXIF_KEY_IMAGE_HEIGHT => 3000,
            Exif::EXIF_KEY_ROTATION => 180,
        ]));
        self::assertSame([4000, 3000], Exif::getDimensions([
            Exif::EXIF_KEY_IMAGE_WIDTH => 4000,
            Exif::EXIF_KEY_IMAGE_HEIGHT => 3000,
            Exif::EXIF_KEY_ROTATION => 0,
        ]));
        foreach ([5, 6, 7, 8] as $orientation) {
            self::assertSame([3000, 4000], Exif::getDimensions([
                Exif::EXIF_KEY_IMAGE_WIDTH => 4000,
                Exif::EXIF_KEY_IMAGE_HEIGHT => 3000,
                Exif::EXIF_KEY_ORIENTATION => $orientation,
            ]), "orientation {$orientation}");
        }
        foreach ([1, 2, 3, 4] as $orientation) {
            self::assertSame([4000, 3000], Exif::getDimensions([
                Exif::EXIF_KEY_IMAGE_WIDTH => 4000,
                Exif::EXIF_KEY_IMAGE_HEIGHT => 3000,
                Exif::EXIF_KEY_ORIENTATION => $orientation,
            ]), "orientation {$orientation}");
        }
        self::assertSame([0, 0], Exif::getDimensions([]));
        self::assertSame([0, 0], Exif::getDimensions([
            Exif::EXIF_KEY_IMAGE_WIDTH => 0,
            Exif::EXIF_KEY_IMAGE_HEIGHT => 3000,
        ]));
        self::assertSame([0, 0], Exif::getDimensions([
            Exif::EXIF_KEY_IMAGE_WIDTH => 4000,
            Exif::EXIF_KEY_IMAGE_HEIGHT => -1,
        ]));
        self::assertSame([0, 0], Exif::getDimensions([
            Exif::EXIF_KEY_IMAGE_WIDTH => 200000,
            Exif::EXIF_KEY_IMAGE_HEIGHT => 3000,
        ]));
        self::assertSame([0, 0], Exif::getDimensions([
            Exif::EXIF_KEY_IMAGE_WIDTH => 4000,
            Exif::EXIF_KEY_IMAGE_HEIGHT => 200000,
        ]));
    }
}
