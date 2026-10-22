<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Listeners\PostWriteListener;
use OCA\Memories\Service\Index;
use OCA\Memories\Service\MIME;
use OCA\Memories\Tests\Injected;
use OCA\Memories\Tests\TestCase;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeTouchedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 *
 * @covers \OCA\Memories\Listeners\PostWriteListener
 */
final class PostWriteListenerTest extends TestCase
{
    #[Injected]
    private Index $indexer;

    #[Injected]
    private MIME $mime;

    #[Injected]
    private ITempManager $tempManager;

    #[DataProvider('provideUnsupportedFilePreservesCallerTemporaryFileCases')]
    public function testUnsupportedFilePreservesCallerTemporaryFile(string $eventClass): void
    {
        $file = self::createStub(File::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getSize')->willReturn(1);
        $file->method('getPath')->willReturn('/test/files/document.pdf');

        $marker = $this->tempManager->getTemporaryFile('_caller.png');
        file_put_contents($marker, 'Caller-owned temporary signature image');

        try {
            $this->listener()->handle($this->fileEvent($eventClass, $file));
            clearstatcache(true, $marker);
            self::assertFileExists($marker);
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    /** @return array<string, array{class-string<Event>}> */
    public static function provideUnsupportedFilePreservesCallerTemporaryFileCases(): iterable
    {
        return [
            'written' => [NodeWrittenEvent::class],
            'touched' => [NodeTouchedEvent::class],
            'copied' => [NodeCopiedEvent::class],
        ];
    }

    #[DataProvider('provideSupportedFileStillReachesIndexerCases')]
    public function testSupportedFileStillReachesIndexer(string $eventClass, string $mime): void
    {
        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn($mime);
        $file->method('getPath')->willReturn('/test/files/empty-media');
        $file->expects(self::once())->method('getSize')->willReturn(0);

        $this->listener()->handle($this->fileEvent($eventClass, $file));
    }

    /** @return array<string, array{class-string<Event>, string}> */
    public static function provideSupportedFileStillReachesIndexerCases(): iterable
    {
        $cases = [];
        foreach (self::provideUnsupportedFilePreservesCallerTemporaryFileCases() as $event => [$eventClass]) {
            foreach (['image/jpeg', 'video/mp4'] as $mime) {
                $cases[$event.' '.$mime] = [$eventClass, $mime];
            }
        }

        return $cases;
    }

    public function testCopiedFolderStillReachesIndexer(): void
    {
        $folder = $this->createMock(Folder::class);
        $folder->expects(self::atLeastOnce())->method('getId')->willReturn(0);
        $folder->method('getPath')->willReturn('/test/files/absent-folder');

        $this->listener()->handle(new NodeCopiedEvent($folder, $folder));
    }

    public function testWrittenFolderIsIgnored(): void
    {
        $folder = $this->createMock(Folder::class);
        $folder->expects(self::never())->method('getId');

        $this->listener()->handle(new NodeWrittenEvent($folder));
    }

    private function listener(): PostWriteListener
    {
        return new PostWriteListener($this->indexer, $this->mime);
    }

    /** @param class-string<Event> $eventClass */
    private function fileEvent(string $eventClass, File $file): Event
    {
        return NodeCopiedEvent::class === $eventClass
            ? new NodeCopiedEvent($file, $file)
            : new $eventClass($file);
    }
}
