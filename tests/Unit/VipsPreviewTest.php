<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Preview\Vips;
use OCA\Memories\Service\BinExt;
use OCA\Memories\Tests\Injected;
use OCA\Memories\Tests\TestCase;
use OCP\Files\File;
use OCP\Files\Storage\IStorage;
use OCP\IConfig;
use OCP\Image;

/**
 * @internal
 *
 * @covers \OCA\Memories\Preview\Vips
 * @covers \OCA\Memories\Service\BinExt
 */
final class VipsPreviewTest extends TestCase
{
    #[Injected]
    private BinExt $binExt;

    #[Injected]
    private Vips $vips;

    #[Injected]
    private static IConfig $config;

    private static bool $origVipsEnabled = true;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$origVipsEnabled = self::$config->getSystemValueBool('memories.vips.enabled', true);
        self::$config->setSystemValue('memories.vips.enabled', true);
    }

    public static function tearDownAfterClass(): void
    {
        self::$config->setSystemValue('memories.vips.enabled', self::$origVipsEnabled);
        parent::tearDownAfterClass();
    }

    public function testDetectAndTestVipsBin(): void
    {
        $path = $this->binExt->detectVips();
        self::assertIsString($path);
        self::assertFileExists($path);

        $version = $this->binExt->testVipsBin($path);
        $upstream = preg_replace('/^v/', '', BinExt::VIPS_VER);
        self::assertSame($upstream, $version);
    }

    public function testGetThumbnail(): void
    {
        self::assertIsString($this->binExt->detectVips());

        $src = realpath(__DIR__.'/../../exiftest.jpg');
        self::assertIsString($src);

        $file = $this->localFileMock($src);

        self::assertTrue($this->vips->isAvailable($file));

        $thumb = $this->vips->getThumbnail($file, 64, 64);
        self::assertNotNull($thumb);
        self::assertTrue($thumb->valid());
        self::assertLessThanOrEqual(64, $thumb->width());
        self::assertLessThanOrEqual(64, $thumb->height());

        // Output is plain JPEG readable by core GD
        $stream = $thumb->resource();
        self::assertTrue(\is_resource($stream));
        $bytes = stream_get_contents($stream);
        self::assertIsString($bytes);
        $image = new Image();
        $image->loadFromData($bytes);
        self::assertTrue($image->valid());
    }

    public function testUnavailableForEmptyFile(): void
    {
        $file = $this->localFileMock(__FILE__, 0);

        self::assertFalse($this->vips->isAvailable($file));
        self::assertNull($this->vips->getThumbnail($file, 64, 64));
    }

    private function localFileMock(string $path, ?int $size = null): File
    {
        $storage = self::createStub(IStorage::class);
        $storage->method('isLocal')->willReturn(true);
        $storage->method('getLocalFile')->willReturn($path);

        $file = self::createStub(File::class);
        $file->method('getSize')->willReturn($size ?? filesize($path));
        $file->method('getStorage')->willReturn($storage);
        $file->method('getInternalPath')->willReturn($path);
        $file->method('getPath')->willReturn($path);

        return $file;
    }
}
