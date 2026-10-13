<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Preview\Vips;
use OCA\Memories\Service\BinExt;
use OCA\Memories\Tests\Injected;
use OCA\Memories\Tests\TestCase;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\File;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\Storage\IStorage;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Server;
use Psr\Log\LoggerInterface;

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
    private IConfig $config;

    #[Injected]
    private IAppConfig $appConfig;

    #[Injected]
    private LoggerInterface $logger;

    public function testDetectAndTestVipsBin(): void
    {
        $path = $this->binExt->detectVips();
        self::assertIsString($path);
        self::assertFileExists($path);

        $version = $this->binExt->testVipsBin($path);
        $upstream = preg_replace('/^v|-.*$/', '', BinExt::VIPS_VER);
        self::assertSame($upstream, $version);
    }

    public function testGetThumbnail(): void
    {
        $src = realpath(__DIR__.'/../../exiftest.jpg');
        self::assertIsString($src);

        // Make sure the binary is detected before generating
        self::assertIsString($this->binExt->detectVips());

        $provider = new Vips($this->binExt, $this->config, $this->appConfig, $this->logger);
        $file = $this->localFileMock($src);

        self::assertTrue($provider->isAvailable($file));

        $thumb = $provider->getThumbnail($file, 64, 64);
        self::assertNotNull($thumb);
        self::assertTrue($thumb->valid());
        self::assertLessThanOrEqual(64, $thumb->width());
        self::assertLessThanOrEqual(64, $thumb->height());
    }

    public function testUnavailableForEmptyFile(): void
    {
        $provider = new Vips($this->binExt, $this->config, $this->appConfig, $this->logger);
        $file = $this->localFileMock(__FILE__, 0);

        self::assertFalse($provider->isAvailable($file));
        self::assertNull($provider->getThumbnail($file, 64, 64));
    }

    public function testRegistersPreviewProviders(): void
    {
        $registrations = [];
        $context = self::createMock(IRegistrationContext::class);
        $context->expects(self::once())
            ->method('registerPreviewProvider')->willReturnCallback(
                static function (string $class, string $regex) use (&$registrations): void {
                    $registrations[$regex] = $class;
                },
            )
        ;

        (new \OCA\Memories\AppInfo\Application())->register($context);

        // Single combined registration taking priority over core providers
        self::assertSame([Vips::MIME_REGEX => Vips::class], $registrations);
        foreach (Vips::MIME_TYPES as $mime) {
            self::assertMatchesRegularExpression(Vips::MIME_REGEX, $mime);
        }

        // Formats unknown to Nextcloud must resolve to their real MIME type
        $detector = Server::get(IMimeTypeDetector::class);
        self::assertSame('image/avif', $detector->detectPath('photo.avif'));
        self::assertSame('image/jxl', $detector->detectPath('photo.jxl'));
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
