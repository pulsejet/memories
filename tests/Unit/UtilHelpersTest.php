<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Tests\TestCase;
use OCA\Memories\Util;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @covers \OCA\Memories\StaticUtil
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

    #[DataProvider('provideGetLibcParseCases')]
    public function testGetLibcParse(string $ldd, string $getconf, ?string $expected): void
    {
        self::assertSame($expected, Util::getLibc($ldd, $getconf));
    }

    public static function provideGetLibcParseCases(): iterable
    {
        return [
            'debian' => ['ldd (Debian GLIBC 2.36) 2.36', '', 'glibc'],
            'arch' => ['ldd (GNU libc) 2.40', '', 'glibc'],
            'alpine' => ['musl libc (x86_64)', '', 'musl'],
            'gentoo getconf' => ['ldd (Gentoo 2.38) 2.38', 'glibc 2.38', 'glibc'],
            'ldd wins over getconf' => ['musl libc (x86_64)', 'glibc 2.41', 'musl'],
            'unknown' => ['ldd (Gentoo 2.38) 2.38', '', null],
            'no partial match' => ['muslim glibcx', 'notglibc', null],
        ];
    }
}
