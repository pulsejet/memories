<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Service\MIME;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @covers \OCA\Memories\Service\MIME
 */
final class IndexLogicTest extends TestCase
{
    public function testIsPathAllowed(): void
    {
        $mime = \OCP\Server::get(MIME::class);

        self::assertTrue($mime->isPathAllowed('/admin/files/Photos/IMG_001.jpg'));
        self::assertTrue($mime->isPathAllowed('/admin/files/Photos/.archive/old.jpg'));

        self::assertFalse($mime->isPathAllowed('/admin/files/Photos/.trashed-12345'));

        self::assertFalse($mime->isPathAllowed('/admin/files/Photos/@Recycle/foo.jpg'));
        self::assertFalse($mime->isPathAllowed('/admin/files/Photos/@eaDir/foo.jpg'));
    }
}
