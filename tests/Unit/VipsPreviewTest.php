<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OC\Preview\Db\Preview;
use OC\Preview\Db\PreviewMapper;
use OC\Preview\Storage\StorageFactory;
use OCA\Memories\Preview\Vips;
use OCA\Memories\Preview\VipsPatcher;
use OCA\Memories\Preview\VipsTrailer;
use OCA\Memories\Service\BinExt;
use OCA\Memories\Tests\Injected;
use OCA\Memories\Tests\TestCase;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\File;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\Storage\IStorage;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Image;
use OCP\ITempManager;
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

    private bool $origVipsEnabled = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->origVipsEnabled = $this->config->getSystemValueBool('memories.vips.enabled', true);
        $this->config->setSystemValue('memories.vips.enabled', true);
    }

    protected function tearDown(): void
    {
        $this->config->setSystemValue('memories.vips.enabled', $this->origVipsEnabled);

        parent::tearDown();
    }

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

        $provider = new Vips($this->binExt, $this->config, $this->appConfig, Server::get(ITempManager::class), $this->logger);
        $file = $this->localFileMock($src);

        self::assertTrue($provider->isAvailable($file));

        $thumb = $provider->getThumbnail($file, 64, 64);
        self::assertNotNull($thumb);
        self::assertTrue($thumb->valid());
        self::assertLessThanOrEqual(64, $thumb->width());
        self::assertLessThanOrEqual(64, $thumb->height());

        // Max rows hit the cache already tagged
        $stream = $thumb->resource();
        self::assertTrue(\is_resource($stream));
        $bytes = stream_get_contents($stream);
        self::assertIsString($bytes);
        self::assertTrue(VipsTrailer::has($bytes));
    }

    public function testUnavailableForEmptyFile(): void
    {
        $provider = new Vips($this->binExt, $this->config, $this->appConfig, Server::get(ITempManager::class), $this->logger);
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
    }

    public function testTrailerRoundTrip(): void
    {
        $trailer = VipsTrailer::build();
        self::assertSame(VipsTrailer::TRAILER_LEN, \strlen($trailer));

        $src = realpath(__DIR__.'/../../exiftest.jpg');
        self::assertIsString($src);
        $data = file_get_contents($src);
        self::assertIsString($data);

        self::assertFalse(VipsTrailer::has($data));
        self::assertFalse(VipsTrailer::has('short'));
        self::assertTrue(VipsTrailer::has($data.$trailer));

        // Old versions still count as vips
        $old = str_pad("\nmemories-vips-v0.0.0-0\n", VipsTrailer::TRAILER_LEN, "\0");
        self::assertTrue(VipsTrailer::has($data.$old));
    }

    public function testGdAcceptsTrailerBytes(): void
    {
        self::assertIsString($this->binExt->detectVips());

        $src = realpath(__DIR__.'/../../exiftest.jpg');
        self::assertIsString($src);

        $provider = new Vips($this->binExt, $this->config, $this->appConfig, Server::get(ITempManager::class), $this->logger);

        $img = $provider->getThumbnailFromPath($src, 64, 64);
        self::assertNotNull($img);
        self::assertTrue($img->valid());
        self::assertLessThanOrEqual(64, $img->width());
        self::assertLessThanOrEqual(64, $img->height());

        $stream = $img->resource();
        self::assertTrue(\is_resource($stream));
        $bytes = stream_get_contents($stream);
        self::assertIsString($bytes);
        self::assertTrue(VipsTrailer::has($bytes));

        // Core loads cached max bytes into GD when deriving small previews
        $image = new Image();
        $image->loadFromData($bytes);
        self::assertTrue($image->valid());

        // Attention crop yields the exact box
        $cropped = $provider->getThumbnailFromPath($src, 64, 32, 'attention');
        self::assertNotNull($cropped);
        self::assertSame(64, $cropped->width());
        self::assertSame(32, $cropped->height());
    }

    public function testPatcherReplacesUntaggedBytes(): void
    {
        self::assertIsString($this->binExt->detectVips());

        $src = realpath(__DIR__.'/../../exiftest.jpg');
        self::assertIsString($src);
        $coreBytes = file_get_contents($src);
        self::assertIsString($coreBytes);
        self::assertFalse(VipsTrailer::has($coreBytes));

        $row = new Preview();
        $row->setFileId(42);
        $row->setStorageId(1);
        $row->setWidth(64);
        $row->setHeight(64);
        $row->setCropped(true);
        $row->setMax(false);
        $row->setMimeType('image/jpeg');
        $row->setSourceMimeType('image/jpeg');
        $row->setEtag('etag42');
        $row->setMtime(time());
        $row->setSize(\strlen($coreBytes));

        $max = new Preview();
        $max->setFileId(42);
        $max->setStorageId(1);
        $max->setWidth(1024);
        $max->setHeight(768);
        $max->setCropped(false);
        $max->setMax(true);
        $max->setMimeType('image/jpeg');
        $max->setSourceMimeType('image/jpeg');
        $max->setEtag('etag42');
        $max->setMtime(time());
        $max->setSize(\strlen($coreBytes));

        $file = $this->localFileMock($src);
        $file->method('getId')->willReturn(42);

        $read = fopen('php://temp', 'w+');
        self::assertTrue(\is_resource($read));
        fwrite($read, $coreBytes);
        rewind($read);

        // Small rows are derived from the max row, never the original
        $maxRead = fopen('php://temp', 'w+');
        self::assertTrue(\is_resource($maxRead));
        fwrite($maxRead, $coreBytes);
        rewind($maxRead);

        $preview = self::createStub(ISimpleFile::class);
        $preview->method('getContent')->willReturn($coreBytes);
        $preview->method('getName')->willReturn($row->getName());
        $preview->method('read')->willReturn($read);

        $mapper = self::createStub(PreviewMapper::class);
        $mapper->method('getAvailablePreviews')->willReturn([42 => [$row, $max]]);
        $mapper->method('update')->willReturnCallback(static fn ($e) => $e);

        $written = null;
        $storage = self::createStub(StorageFactory::class);
        $storage->method('readPreview')->willReturn($maxRead);
        $storage->method('writePreview')->willReturnCallback(static function ($preview, $stream) use (&$written): int {
            $written = stream_get_contents($stream);

            return \strlen((string) $written);
        });

        $patcher = new VipsPatcher(new Vips($this->binExt, $this->config, $this->appConfig, Server::get(ITempManager::class), $this->logger), $mapper, $storage, $this->logger);
        self::assertTrue($patcher->patch($file, $preview));

        self::assertIsString($written);
        self::assertTrue(VipsTrailer::has($written));
        self::assertLessThanOrEqual(64, getimagesizefromstring($written)[0]);
    }

    public function testPatcherReplacesUncroppedBytes(): void
    {
        self::assertIsString($this->binExt->detectVips());

        $src = realpath(__DIR__.'/../../exiftest.jpg');
        self::assertIsString($src);
        $coreBytes = file_get_contents($src);
        self::assertIsString($coreBytes);
        self::assertFalse(VipsTrailer::has($coreBytes));

        $row = new Preview();
        $row->setFileId(42);
        $row->setStorageId(1);
        $row->setWidth(64);
        $row->setHeight(32);
        $row->setCropped(false);
        $row->setMax(false);
        $row->setMimeType('image/jpeg');
        $row->setSourceMimeType('image/jpeg');
        $row->setEtag('etag42');
        $row->setMtime(time());
        $row->setSize(\strlen($coreBytes));

        $max = new Preview();
        $max->setFileId(42);
        $max->setStorageId(1);
        $max->setWidth(100);
        $max->setHeight(75);
        $max->setCropped(false);
        $max->setMax(true);
        $max->setMimeType('image/jpeg');
        $max->setSourceMimeType('image/jpeg');
        $max->setEtag('etag42');
        $max->setMtime(time());
        $max->setSize(\strlen($coreBytes));

        $file = $this->localFileMock($src);
        $file->method('getId')->willReturn(42);

        $read = fopen('php://temp', 'w+');
        self::assertTrue(\is_resource($read));
        fwrite($read, $coreBytes);
        rewind($read);

        $maxRead = fopen('php://temp', 'w+');
        self::assertTrue(\is_resource($maxRead));
        fwrite($maxRead, $coreBytes);
        rewind($maxRead);

        $preview = self::createStub(ISimpleFile::class);
        $preview->method('getContent')->willReturn($coreBytes);
        $preview->method('getName')->willReturn($row->getName());
        $preview->method('read')->willReturn($read);

        $mapper = self::createStub(PreviewMapper::class);
        $mapper->method('getAvailablePreviews')->willReturn([42 => [$row, $max]]);
        $mapper->method('update')->willReturnCallback(static fn ($e) => $e);

        $written = null;
        $storage = self::createStub(StorageFactory::class);
        $storage->method('readPreview')->willReturn($maxRead);
        $storage->method('writePreview')->willReturnCallback(static function ($preview, $stream) use (&$written): int {
            $written = stream_get_contents($stream);

            return \strlen((string) $written);
        });

        $patcher = new VipsPatcher(new Vips($this->binExt, $this->config, $this->appConfig, Server::get(ITempManager::class), $this->logger), $mapper, $storage, $this->logger);

        self::assertTrue($patcher->patch($file, $preview));

        self::assertIsString($written);
        self::assertTrue(VipsTrailer::has($written));
        self::assertLessThanOrEqual(64, getimagesizefromstring($written)[0]);
        self::assertLessThanOrEqual(32, getimagesizefromstring($written)[1]);
    }

    public function testPatcherSkipsMaxRows(): void
    {
        $src = realpath(__DIR__.'/../../exiftest.jpg');
        self::assertIsString($src);
        $coreBytes = file_get_contents($src);
        self::assertIsString($coreBytes);

        $max = new Preview();
        $max->setFileId(42);
        $max->setStorageId(1);
        $max->setWidth(1024);
        $max->setHeight(768);
        $max->setCropped(false);
        $max->setMax(true);
        $max->setMimeType('image/jpeg');
        $max->setSourceMimeType('image/jpeg');
        $max->setEtag('etag42');
        $max->setMtime(time());
        $max->setSize(\strlen($coreBytes));

        $read = fopen('php://temp', 'w+');
        self::assertTrue(\is_resource($read));
        fwrite($read, $coreBytes);
        rewind($read);

        $preview = self::createStub(ISimpleFile::class);
        $preview->method('getContent')->willReturn($coreBytes);
        $preview->method('getName')->willReturn($max->getName());
        $preview->method('read')->willReturn($read);

        $file = $this->localFileMock($src);
        $file->method('getId')->willReturn(42);

        $storage = self::createMock(StorageFactory::class);
        $storage->expects(self::never())->method('writePreview');

        $mapper = self::createStub(PreviewMapper::class);
        $mapper->method('getAvailablePreviews')->willReturn([42 => [$max]]);

        $patcher = new VipsPatcher(new Vips($this->binExt, $this->config, $this->appConfig, Server::get(ITempManager::class), $this->logger), $mapper, $storage, $this->logger);

        self::assertFalse($patcher->patch($file, $preview));
    }

    public function testPatcherSkipsNonJpegRows(): void
    {
        $src = realpath(__DIR__.'/../../exiftest.jpg');
        self::assertIsString($src);
        $coreBytes = file_get_contents($src);
        self::assertIsString($coreBytes);

        $row = new Preview();
        $row->setFileId(42);
        $row->setStorageId(1);
        $row->setWidth(64);
        $row->setHeight(64);
        $row->setCropped(false);
        $row->setMax(false);
        $row->setMimeType('image/png');
        $row->setSourceMimeType('image/png');
        $row->setEtag('etag42');
        $row->setMtime(time());
        $row->setSize(\strlen($coreBytes));

        $max = new Preview();
        $max->setFileId(42);
        $max->setStorageId(1);
        $max->setWidth(1024);
        $max->setHeight(768);
        $max->setCropped(false);
        $max->setMax(true);
        $max->setMimeType('image/png');
        $max->setSourceMimeType('image/png');
        $max->setEtag('etag42');
        $max->setMtime(time());
        $max->setSize(\strlen($coreBytes));

        $read = fopen('php://temp', 'w+');
        self::assertTrue(\is_resource($read));
        fwrite($read, $coreBytes);
        rewind($read);

        $preview = self::createStub(ISimpleFile::class);
        $preview->method('getContent')->willReturn($coreBytes);
        $preview->method('getName')->willReturn($row->getName());
        $preview->method('read')->willReturn($read);

        $file = $this->localFileMock($src);
        $file->method('getId')->willReturn(42);

        $storage = self::createMock(StorageFactory::class);
        $storage->expects(self::never())->method('writePreview');

        $mapper = self::createStub(PreviewMapper::class);
        $mapper->method('getAvailablePreviews')->willReturn([42 => [$row, $max]]);

        $patcher = new VipsPatcher(new Vips($this->binExt, $this->config, $this->appConfig, Server::get(ITempManager::class), $this->logger), $mapper, $storage, $this->logger);

        self::assertFalse($patcher->patch($file, $preview));
    }

    public function testPatcherFindsMaxRegardlessOfOrder(): void
    {
        self::assertIsString($this->binExt->detectVips());

        $src = realpath(__DIR__.'/../../exiftest.jpg');
        self::assertIsString($src);
        $coreBytes = file_get_contents($src);
        self::assertIsString($coreBytes);

        $row = new Preview();
        $row->setFileId(42);
        $row->setStorageId(1);
        $row->setWidth(64);
        $row->setHeight(64);
        $row->setCropped(true);
        $row->setMax(false);
        $row->setMimeType('image/jpeg');
        $row->setSourceMimeType('image/jpeg');
        $row->setEtag('etag42');
        $row->setMtime(time());
        $row->setSize(\strlen($coreBytes));

        $max = new Preview();
        $max->setFileId(42);
        $max->setStorageId(1);
        $max->setWidth(1024);
        $max->setHeight(768);
        $max->setCropped(false);
        $max->setMax(true);
        $max->setMimeType('image/jpeg');
        $max->setSourceMimeType('image/jpeg');
        $max->setEtag('etag42');
        $max->setMtime(time());
        $max->setSize(\strlen($coreBytes));

        $file = $this->localFileMock($src);
        $file->method('getId')->willReturn(42);

        $read = fopen('php://temp', 'w+');
        self::assertTrue(\is_resource($read));
        fwrite($read, $coreBytes);
        rewind($read);

        $maxRead = fopen('php://temp', 'w+');
        self::assertTrue(\is_resource($maxRead));
        fwrite($maxRead, $coreBytes);
        rewind($maxRead);

        $preview = self::createStub(ISimpleFile::class);
        $preview->method('getContent')->willReturn($coreBytes);
        $preview->method('getName')->willReturn($row->getName());
        $preview->method('read')->willReturn($read);

        $mapper = self::createStub(PreviewMapper::class);
        $mapper->method('getAvailablePreviews')->willReturn([42 => [$max, $row]]);
        $mapper->method('update')->willReturnCallback(static fn ($e) => $e);

        $written = null;
        $storage = self::createStub(StorageFactory::class);
        $storage->method('readPreview')->willReturn($maxRead);
        $storage->method('writePreview')->willReturnCallback(static function ($preview, $stream) use (&$written): int {
            $written = stream_get_contents($stream);

            return \strlen((string) $written);
        });

        $patcher = new VipsPatcher(new Vips($this->binExt, $this->config, $this->appConfig, Server::get(ITempManager::class), $this->logger), $mapper, $storage, $this->logger);
        self::assertTrue($patcher->patch($file, $preview));

        self::assertIsString($written);
        self::assertTrue(VipsTrailer::has($written));
    }

    public function testPatcherRejectsVersionMismatch(): void
    {
        $src = realpath(__DIR__.'/../../exiftest.jpg');
        self::assertIsString($src);
        $coreBytes = file_get_contents($src);
        self::assertIsString($coreBytes);

        $row = new Preview();
        $row->setFileId(42);
        $row->setStorageId(1);
        $row->setWidth(64);
        $row->setHeight(64);
        $row->setCropped(true);
        $row->setMax(false);
        $row->setMimeType('image/jpeg');
        $row->setSourceMimeType('image/jpeg');
        $row->setEtag('etag42');
        $row->setMtime(time());
        $row->setSize(\strlen($coreBytes));
        $row->setVersion('a');

        $max = new Preview();
        $max->setFileId(42);
        $max->setStorageId(1);
        $max->setWidth(1024);
        $max->setHeight(768);
        $max->setCropped(false);
        $max->setMax(true);
        $max->setMimeType('image/jpeg');
        $max->setSourceMimeType('image/jpeg');
        $max->setEtag('etag42');
        $max->setMtime(time());
        $max->setSize(\strlen($coreBytes));
        $max->setVersion('b');

        $file = $this->localFileMock($src);
        $file->method('getId')->willReturn(42);

        $read = fopen('php://temp', 'w+');
        self::assertTrue(\is_resource($read));
        fwrite($read, $coreBytes);
        rewind($read);

        $preview = self::createStub(ISimpleFile::class);
        $preview->method('getContent')->willReturn($coreBytes);
        $preview->method('getName')->willReturn($row->getName());
        $preview->method('read')->willReturn($read);

        $storage = self::createMock(StorageFactory::class);
        $storage->expects(self::never())->method('writePreview');

        $mapper = self::createStub(PreviewMapper::class);
        $mapper->method('getAvailablePreviews')->willReturn([42 => [$max, $row]]);

        $patcher = new VipsPatcher(new Vips($this->binExt, $this->config, $this->appConfig, Server::get(ITempManager::class), $this->logger), $mapper, $storage, $this->logger);

        self::assertFalse($patcher->patch($file, $preview));
    }

    public function testPatcherSkipsTaggedBytes(): void
    {
        $blob = str_repeat('x', 100).VipsTrailer::build();

        $read = fopen('php://temp', 'w+');
        self::assertTrue(\is_resource($read));
        fwrite($read, $blob);
        rewind($read);

        $preview = self::createStub(ISimpleFile::class);
        $preview->method('getContent')->willReturn($blob);
        $preview->method('read')->willReturn($read);

        $file = $this->localFileMock(__FILE__);

        $storage = self::createMock(StorageFactory::class);
        $storage->expects(self::never())->method('writePreview');

        $mapper = self::createStub(PreviewMapper::class);

        $patcher = new VipsPatcher(new Vips($this->binExt, $this->config, $this->appConfig, Server::get(ITempManager::class), $this->logger), $mapper, $storage, $this->logger);

        self::assertFalse($patcher->patch($file, $preview, []));
    }

    public function testPatcherReturnsFalseWhenNoRow(): void
    {
        $src = realpath(__DIR__.'/../../exiftest.jpg');
        self::assertIsString($src);
        $coreBytes = file_get_contents($src);
        self::assertIsString($coreBytes);

        $read = fopen('php://temp', 'w+');
        self::assertTrue(\is_resource($read));
        fwrite($read, $coreBytes);
        rewind($read);

        $preview = self::createStub(ISimpleFile::class);
        $preview->method('getContent')->willReturn($coreBytes);
        $preview->method('getName')->willReturn('64-64.jpg');
        $preview->method('read')->willReturn($read);

        $file = $this->localFileMock($src);
        $storage = self::createMock(StorageFactory::class);
        $storage->expects(self::never())->method('writePreview');

        $patcher = new VipsPatcher(
            new Vips($this->binExt, $this->config, $this->appConfig, Server::get(ITempManager::class), $this->logger),
            self::createStub(PreviewMapper::class),
            $storage,
            $this->logger,
        );

        self::assertFalse($patcher->patch($file, $preview, []));
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
