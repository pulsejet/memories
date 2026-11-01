<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Db\CTEParams;
use OCA\Memories\Service\MIME;
use OCA\Memories\Tests\TestCase;

/**
 * @internal
 *
 * @covers \OCA\Memories\Service\MIME
 */
final class MIMEHelpersTest extends TestCase
{
    public function testGetExtension(): void
    {
        self::assertSame('png', MIME::getExtension('a.png'));
        self::assertSame('png', MIME::getExtension('a.PNG'));
        self::assertSame('gz', MIME::getExtension('archive.tar.gz'));
        self::assertSame('jpg', MIME::getExtension('/some/path/to/photo.jpg'));
        self::assertSame('', MIME::getExtension('noext'));
        self::assertSame('', MIME::getExtension('.gitignore'));
        self::assertSame('', MIME::getExtension('a.'));
        self::assertSame('', MIME::getExtension(''));
    }

    public function testNormalizeExtensions(): void
    {
        self::assertSame(
            ['png', 'gif', 'bmp'],
            MIME::normalizeExtensions(['*.PNG', ' gif ', '.bmp', '', 'png']),
        );

        self::assertSame([], MIME::normalizeExtensions([]));
        self::assertSame([], MIME::normalizeExtensions(['', '  ', '.', '*']));
    }

    public function testEscapeLikePattern(): void
    {
        self::assertSame('png', CTEParams::escapeLikePattern('png'));

        // The escape character is a backslash
        $escape = '\\';

        // LIKE wildcards must be escaped to match literally
        self::assertSame('r'.$escape.'_aw', CTEParams::escapeLikePattern('r_aw'));
        self::assertSame('100'.$escape.'%', CTEParams::escapeLikePattern('100%'));

        // A literal backslash must be escaped too
        self::assertSame('a'.$escape.$escape.'b', CTEParams::escapeLikePattern('a'.$escape.'b'));
    }
}
