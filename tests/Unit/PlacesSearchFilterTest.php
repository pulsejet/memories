<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\ClustersBackend\PlacesSearchFilter;
use OCA\Memories\Tests\TestCase;
use OCP\IDBConnection;
use OCP\Server;

/**
 * @internal
 *
 * @coversNothing
 */
final class PlacesSearchFilterTest extends TestCase
{
    public function testPlacesRespectIntersectionsExclusionsAndExistingRestrictions(): void
    {
        if (!\in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('SQLite PDO required');
        }
        $connection = Server::get(IDBConnection::class);
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE memories (fileid INTEGER)');
        $db->exec('CREATE TABLE memories_places (fileid INTEGER, osm_id INTEGER)');
        $db->exec('INSERT INTO memories VALUES (1), (2), (3), (4)');
        $db->exec('INSERT INTO memories_places VALUES (1, 10), (1, 10), (1, 20), (2, 10), (3, 20), (4, 10)');
        foreach (['10' => [1, 2], '10,-20' => [2], '10,20' => [1], '-10' => [3], '99' => []] as $filter => $expected) {
            foreach ([false, true] as $aggregate) {
                $query = $connection->getQueryBuilder();
                $query->select('m.fileid')->from('memories', 'm')
                    ->where($query->expr()->neq('m.fileid', $query->createNamedParameter(4, \PDO::PARAM_INT)))
                ;
                if ($aggregate) {
                    $query->select($query->func()->count('m.fileid'));
                }
                PlacesSearchFilter::apply($query, $connection->getQueryBuilder(...), (string) $filter);
                $stmt = $db->prepare(str_replace('*PREFIX*', '', $query->getSQL()));
                $stmt->execute($query->getParameters());
                if ($aggregate) {
                    self::assertSame(\count($expected), (int) $stmt->fetchColumn());
                } else {
                    self::assertSame($expected, array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN)));
                }
            }
        }
    }
}
