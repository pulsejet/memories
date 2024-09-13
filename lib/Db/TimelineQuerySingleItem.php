<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\ClustersBackend\PlacesBackend;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

trait TimelineQuerySingleItem
{
    protected IDBConnection $connection;

    public function getSingleItem(int $fileId): ?array
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

        // Check if photo was found
        if (false === $photo) {
            return null;
        }

        // Post process the record
        $this->postProcessDayPhoto($photo);

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

        // Basic information to return
        $info = [
            'fileid' => (int) $row['fileid'],
            'dayid' => (int) $row['dayid'],
            'w' => (int) $row['w'],
            'h' => (int) $row['h'],
            'datetaken' => Util::sqlUtcToTimestamp($row['datetaken']),
        ];

        // Return if only basic info is needed
        if ($basic) {
            return $info;
        }

        // Get exif data for metadata
        if (!empty($row['exif'])) {
            try {
                $info['exif'] = json_decode($row['exif'], true);
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        // Get address from places
        if (SystemConfig::gisType() > 0) {
            // Get names of places for this file
            $qb = $this->connection->getQueryBuilder();
            $places = $qb->select('e.name', 'e.other_names')
                ->from('memories_places', 'mp')
                ->innerJoin('mp', 'memories_planet', 'e', $qb->expr()->eq('mp.osm_id', 'e.osm_id'))
                ->andWhere($qb->expr()->eq('mp.fileid', $qb->createNamedParameter($id, \PDO::PARAM_INT)))
                ->andWhere($qb->expr()->gt('e.admin_level', $qb->expr()->literal(0, \PDO::PARAM_INT)))
                ->addOrderBy('e.admin_level', 'DESC')
                ->executeQuery()
                ->fetchAll()
            ;

            if (\count($places)) {
                // Get user language
                $lang = Util::getUserLang();

                // Get translated address
                $info['address'] = implode(', ', array_map(
                    static fn ($p): string => PlacesBackend::translateName(
                        $lang,
                        $p['name'],
                        $p['other_names'],
                    ),
                    $places,
                ));
            }
        }

        return $info;
    }
}
