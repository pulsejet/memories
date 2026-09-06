<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Util;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @covers \OCA\Memories\Util
 */
final class UtilHelpersTest extends TestCase
{
    public function testSanitizePath(): void
    {
        self::assertSame('/Photos', Util::sanitizePath('/Photos'));
        self::assertSame('/Photos/foo', Util::sanitizePath('//Photos//foo'));
        self::assertSame('/Photos', Util::sanitizePath('Photos'));
        self::assertNull(Util::sanitizePath('/Photos/../evil'));
    }

    public function testExplodeExact(): void
    {
        self::assertSame(['a', 'b', '', ''], Util::explode_exact(',', 'a,b', 4));
        self::assertSame(['a', 'b', 'c:d'], Util::explode_exact(':', 'a:b:c:d', 3));
    }

    public function testSqlUtcToTimestamp(): void
    {
        self::assertSame(1678042697, Util::sqlUtcToTimestamp('2023-03-05 18:58:17'));
        self::assertSame(0, Util::sqlUtcToTimestamp('not-a-date'));
    }

    public function testGetArchLibc(): void
    {
        self::assertContains(Util::getArch(), ['amd64', 'aarch64']);
        self::assertContains(Util::getLibc(), ['glibc', 'musl']);
    }
}
