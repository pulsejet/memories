<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Service\Index;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @covers \OCA\Memories\Service\Index
 */
final class IndexLogicTest extends TestCase
{
    private const BLACKLIST = '\/@(Recycle|eaDir)\/';

    public function testIsPathAllowed(): void
    {
        self::assertTrue(Index::isPathAllowed('/admin/files/Photos/IMG_001.jpg', self::BLACKLIST));
        self::assertTrue(Index::isPathAllowed('/admin/files/Photos/.archive/old.jpg', self::BLACKLIST));

        self::assertFalse(Index::isPathAllowed('/admin/files/Photos/.trashed-12345', self::BLACKLIST));

        self::assertFalse(Index::isPathAllowed('/admin/files/Photos/@Recycle/foo.jpg', self::BLACKLIST));
        self::assertFalse(Index::isPathAllowed('/admin/files/Photos/@eaDir/foo.jpg', self::BLACKLIST));
    }
}
