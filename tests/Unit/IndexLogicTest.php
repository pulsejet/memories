<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Service\Index;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @covers \OCA\Memories\Service\Index
 */
final class IndexLogicTest extends TestCase
{
    public function testIsPathAllowed(): void
    {
        self::assertTrue(Index::isPathAllowed('/admin/files/Photos/IMG_001.jpg'));
        self::assertTrue(Index::isPathAllowed('/admin/files/Photos/.archive/old.jpg'));

        self::assertFalse(Index::isPathAllowed('/admin/files/Photos/.trashed-12345'));

        self::assertFalse(Index::isPathAllowed('/admin/files/Photos/@Recycle/foo.jpg'));
        self::assertFalse(Index::isPathAllowed('/admin/files/Photos/@eaDir/foo.jpg'));
    }

    public function testGetAllMimesMergesImagesAndVideos(): void
    {
        $all = Index::getAllMimes();

        foreach (Application::IMAGE_MIMES as $mime) {
            self::assertContains($mime, $all);
        }

        foreach (Application::VIDEO_MIMES as $mime) {
            self::assertContains($mime, $all);
        }

        self::assertNotContains('application/pdf', $all);
    }

    public function testGetMimeListOnlyContainsSupportedMedia(): void
    {
        $mimes = Index::getMimeList();

        self::assertContains('image/jpeg', $mimes);
        self::assertContains('video/mp4', $mimes);
        self::assertNotContains('application/pdf', $mimes);

        foreach ($mimes as $mime) {
            self::assertContains($mime, Index::getAllMimes());
        }
    }

    private function nodeWithMime(string $mime): Node
    {
        $node = self::createStub(Node::class);
        $node->method('getMimeType')->willReturn($mime);

        return $node;
    }
}
