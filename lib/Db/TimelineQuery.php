<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class TimelineQuery
{
    use TimelineQueryAlbums;
    use TimelineQueryDays;
    use TimelineQueryFilters;
    use TimelineQueryFolders;
    use TimelineQueryLivePhoto;
    use TimelineQueryMap;
    use TimelineQueryPeopleFaceRecognition;
    use TimelineQueryPeopleRecognize;
    use TimelineQueryPlaces;
    use TimelineQuerySingleItem;
    use TimelineQueryTags;

    public const TIMELINE_SELECT = [
        'm.isvideo', 'm.video_duration', 'm.datetaken', 'm.dayid', 'm.w', 'm.h', 'm.liveid',
        'f.etag', 'f.name AS basename', 'mimetypes.mimetype',
    ];

    protected IDBConnection $connection;

    public function __construct(IDBConnection $connection)
    {
        $this->connection = $connection;
    }

    public static function debugQuery(IQueryBuilder &$query, string $sql = '')
    {
        // Print the query and exit
        $sql = empty($sql) ? $query->getSQL() : $sql;
        $sql = str_replace('*PREFIX*', 'oc_', $sql);
        $sql = self::replaceQueryParams($query, $sql);
        echo "{$sql}";

        exit;
    }

    public static function replaceQueryParams(IQueryBuilder &$query, string $sql)
    {
        $params = $query->getParameters();
        foreach ($params as $key => $value) {
            if (\is_array($value)) {
                $value = implode(',', $value);
            } elseif (\is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (null === $value) {
                $value = 'NULL';
            }

            $value = $query->getConnection()->getDatabasePlatform()->quoteStringLiteral($value);
            $sql = str_replace(':'.$key, $value, $sql);
        }

        return $sql;
    }

    public function transformExtraFields(IQueryBuilder &$query, string $uid, array &$fields)
    {
    }

    public function getInfoById(int $id, bool $basic): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('fileid', 'dayid', 'datetaken', 'w', 'h')
            ->from('memories')
            ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($id, \PDO::PARAM_INT)))
        ;

        if (!$basic) {
            $qb->addSelect('exif');
        }

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        $utcTs = 0;

        try {
            $utcDate = new \DateTime($row['datetaken'], new \DateTimeZone('UTC'));
            $utcTs = $utcDate->getTimestamp();
        } catch (\Throwable $e) {
        }

        $exif = [];
        if (!$basic && !empty($row['exif'])) {
            try {
                $exif = json_decode($row['exif'], true);
            } catch (\Throwable $e) {
            }
        }

        $gisType = \OCA\Memories\Util::placesGISType();
        $address = -1 === $gisType ? 'Geocoding Unconfigured' : null;
        if (!$basic && $gisType > 0) {
            $qb = $this->connection->getQueryBuilder();
            $qb->select('e.name')
                ->from('memories_places', 'mp')
                ->innerJoin('mp', 'memories_planet', 'e', $qb->expr()->eq('mp.osm_id', 'e.osm_id'))
                ->where($qb->expr()->eq('mp.fileid', $qb->createNamedParameter($id, \PDO::PARAM_INT)))
                ->orderBy('e.admin_level', 'DESC')
            ;
            $places = $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN);
            if (\count($places) > 0) {
                $address = implode(', ', $places);
            }
        }

        return [
            'fileid' => (int) $row['fileid'],
            'dayid' => (int) $row['dayid'],
            'w' => (int) $row['w'],
            'h' => (int) $row['h'],
            'datetaken' => $utcTs,
            'address' => $address,
            'exif' => $exif,
        ];
    }
}
