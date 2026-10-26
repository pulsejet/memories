<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\ClustersBackend\RecognizePeopleFilter;
use OCA\Memories\Tests\TestCase;
use OCP\IDBConnection;
use OCP\Server;

/**
 * @internal
 *
 * @coversNothing
 */
final class RecognizePeopleFilterTest extends TestCase
{
    public function testEmptyFilter(): void
    {
        self::assertSame([], RecognizePeopleFilter::parse(null));
        self::assertSame([], RecognizePeopleFilter::parse(''));
    }

    public function testDuplicatePeopleAreCollapsed(): void
    {
        self::assertSame([12, 34], RecognizePeopleFilter::parse('12,34,12'));
    }

    public function testInvalidIdsAreRejected(): void
    {
        foreach (['0', '-1', '1.2', '01', '1,,2', '1 OR 1=1', '9999999999999999999', ['1'], true, 1] as $value) {
            try {
                RecognizePeopleFilter::parse($value);
                self::fail('Invalid people filter was accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testEightAdditionalPeopleAreAccepted(): void
    {
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8], RecognizePeopleFilter::parse('1,2,3,4,5,6,7,8'));
    }

    public function testOversizedInputIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RecognizePeopleFilter::parse(str_repeat('1', 161));
    }

    public function testPeopleLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RecognizePeopleFilter::parse('1,2,3,4,5,6,7,8,9');
    }

    public function testAllPeopleWithoutDuplicatesOrOtherUsers(): void
    {
        if (!\in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('SQLite PDO required for isolated query fixtures');
        }
        $connection = Server::get(IDBConnection::class);
        $query = $connection->getQueryBuilder();
        $query->select('m.fileid')->from('memories', 'm');
        RecognizePeopleFilter::apply($query, $connection->getQueryBuilder(...), 'alice', '10,20,10');

        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE memories (fileid INTEGER)');
        $db->exec('CREATE TABLE recognize_face_clusters (id INTEGER, user_id TEXT)');
        $db->exec('CREATE TABLE recognize_face_detections (cluster_id INTEGER, file_id INTEGER, user_id TEXT)');
        $db->exec('INSERT INTO memories VALUES (1), (2), (3), (4)');
        $db->exec("INSERT INTO recognize_face_clusters VALUES (10, 'alice'), (20, 'alice'), (30, 'bob')");
        $db->exec("INSERT INTO recognize_face_detections VALUES
            (10, 1, 'alice'), (20, 1, 'alice'), (20, 1, 'alice'),
            (10, 2, 'alice'), (30, 2, 'bob'), (20, 3, 'alice'), (10, 4, 'alice'), (20, 4, 'bob')");
        $sql = str_replace('*PREFIX*', '', $query->getSQL());
        $stmt = $db->prepare($sql);
        $stmt->execute($query->getParameters());
        self::assertSame([1], array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN)));

        $query = $connection->getQueryBuilder();
        $query->select($query->func()->count('m.fileid'))->from('memories', 'm');
        RecognizePeopleFilter::apply($query, $connection->getQueryBuilder(...), 'alice', '10,20');
        $stmt = $db->prepare(str_replace('*PREFIX*', '', $query->getSQL()));
        $stmt->execute($query->getParameters());
        self::assertSame(1, (int) $stmt->fetchColumn());

        $query = $connection->getQueryBuilder();
        $query->select('m.fileid')->from('memories', 'm')
            ->where($query->expr()->neq('m.fileid', $query->createNamedParameter(1, \PDO::PARAM_INT)))
        ;
        RecognizePeopleFilter::apply($query, $connection->getQueryBuilder(...), 'alice', '10,20');
        $stmt = $db->prepare(str_replace('*PREFIX*', '', $query->getSQL()));
        $stmt->execute($query->getParameters());
        self::assertSame([], $stmt->fetchAll());

        $query = $connection->getQueryBuilder();
        $query->select('m.fileid')->from('memories', 'm');
        RecognizePeopleFilter::apply($query, $connection->getQueryBuilder(...), 'alice', '30');
        $stmt = $db->prepare(str_replace('*PREFIX*', '', $query->getSQL()));
        $stmt->execute($query->getParameters());
        self::assertSame([], $stmt->fetchAll());
    }
}
