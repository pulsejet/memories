<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\ClustersBackend\PlacesBackend;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

trait TimelineQuerySingleItem
{
    protected IDBConnection $connection;

    public function getSingleItem(int $fileId)
    {
        $query = $this->connection->getQueryBuilder();
        $query->select('m.fileid', ...TimelineQuery::TIMELINE_SELECT)
            ->from('memories', 'm')
            ->where($query->expr()->eq('m.fileid', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
        ;

        // JOIN filecache for etag
        $query->innerJoin('m', 'filecache', 'f', $query->expr()->eq('f.fileid', 'm.fileid'));

        // JOIN with mimetypes to get the mimetype
        $query->join('f', 'mimetypes', 'mimetypes', $query->expr()->eq('f.mimetype', 'mimetypes.id'));

        // FETCH the photo
        $photo = $query->executeQuery()->fetch();

        // Post process the record
        $this->processDayPhoto($photo);

        return $photo;
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

        $row = $qb->executeQuery()->fetch();

        $utcTs = 0;

        try {
            $utcDate = new \DateTime($row['datetaken'], new \DateTimeZone('UTC'));
            $utcTs = $utcDate->getTimestamp();
        } catch (\Throwable $e) {
        }

        // Get exif if needed
        $exif = [];
        if (!$basic && !empty($row['exif'])) {
            try {
                $exif = json_decode($row['exif'], true);
            } catch (\Throwable $e) {
            }
        }

        // Get address from places
        $gisType = \OCA\Memories\Util::placesGISType();
        $address = -1 === $gisType ? 'Geocoding Unconfigured' : null;
        if (!$basic && $gisType > 0) {
            $qb = $this->connection->getQueryBuilder();
            $qb->select('e.name', 'e.other_names')
                ->from('memories_places', 'mp')
                ->innerJoin('mp', 'memories_planet', 'e', $qb->expr()->eq('mp.osm_id', 'e.osm_id'))
                ->where($qb->expr()->eq('mp.fileid', $qb->createNamedParameter($id, \PDO::PARAM_INT)))
                ->andWhere($qb->expr()->gt('e.admin_level', $qb->createNamedParameter(0)))
                ->orderBy('e.admin_level', 'DESC')
            ;

            $places = $qb->executeQuery()->fetchAll();
            $lang = Util::getUserLang();
            if (\count($places) > 0) {
                $places = array_map(fn ($p) => PlacesBackend::choosePlaceLang($p, $lang)['name'], $places);
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
