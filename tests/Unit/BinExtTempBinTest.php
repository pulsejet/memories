<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Service\BinExt;
use OCA\Memories\Tests\Injected;
use OCA\Memories\Tests\TestCase;

/**
 * @internal
 *
 * @covers \OCA\Memories\Service\BinExt
 */
final class BinExtTempBinTest extends TestCase
{
    #[Injected]
    private BinExt $binExt;

    public function testGetTempBinCopiesAndCaches(): void
    {
        $src = tempnam(sys_get_temp_dir(), 'memories-src-');
        file_put_contents($src, "#!/bin/sh\necho hi\n");
        $name = 'testbin-'.bin2hex(random_bytes(4));

        $target = $this->binExt->getTempBin($src, $name);
        self::assertFileExists($target);
        self::assertSame(file_get_contents($src), file_get_contents($target));
        self::assertTrue(is_executable($target));
        self::assertSame($target, $this->binExt->getTempBin($src, $name));

        unlink($src);
    }

    public function testGetTempBinNeedsNoWritability(): void
    {
        $src = tempnam(sys_get_temp_dir(), 'memories-src-');
        file_put_contents($src, 'data');
        $name = 'testbin-'.bin2hex(random_bytes(4));

        $target = $this->binExt->getTempBin($src, $name);
        chmod($target, 0o555);

        self::assertSame($target, $this->binExt->getTempBin($src, $name));

        unlink($src);
    }
}
