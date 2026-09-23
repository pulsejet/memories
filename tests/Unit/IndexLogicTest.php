<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Service\MIME;
use OCA\Memories\Tests\Injected;
use OCA\Memories\Tests\TestCase;

/**
 * @internal
 *
 * @covers \OCA\Memories\Service\MIME
 */
final class IndexLogicTest extends TestCase
{
    #[Injected]
    private MIME $mime;

    public function testIsPathAllowed(): void
    {
        self::assertTrue($this->mime->isPathAllowed('/admin/files/Photos/'));
        self::assertTrue($this->mime->isPathAllowed('/admin/files/Photos/.archive/'));
        self::assertTrue($this->mime->isPathAllowed('/admin/files/Photos/IMG_001.jpg/'));

        // Default blocklist: @Recycle, @eaDir, .trashed-%
        self::assertFalse($this->mime->isPathAllowed('/admin/files/Photos/@Recycle/'));
        self::assertFalse($this->mime->isPathAllowed('/admin/files/Photos/@eaDir/'));
        self::assertFalse($this->mime->isPathAllowed('/admin/files/Photos/.trashed-12345/'));

        // Files (no trailing slash): own name never matched, ancestors are
        self::assertTrue($this->mime->isPathAllowed('/admin/files/Photos/IMG_001.jpg'));
        self::assertTrue($this->mime->isPathAllowed('/admin/files/Photos/@Recycle'));
        self::assertFalse($this->mime->isPathAllowed('/admin/files/Photos/@Recycle/foo.jpg'));
    }
}
