<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\IDBConnection;

class LivePhoto
{
    protected IDBConnection $connection;

    public function __construct(IDBConnection $connection)
    {
        $this->connection = $connection;
    }

    /** Check if a given Exif data is the video part of a live photo */
    public function isVideoPart(array &$exif)
    {
        return \array_key_exists('MIMEType', $exif)
               && 'video/quicktime' === $exif['MIMEType']
               && \array_key_exists('ContentIdentifier', $exif);
    }

    /** Get liveid from photo part */
    public function getLivePhotoId(array &$exif)
    {
        // Apple JPEG (MOV has ContentIdentifier)
        if (\array_key_exists('MediaGroupUUID', $exif)) {
            return $exif['MediaGroupUUID'];
        }

        // Samsung JPEG
        if (\array_key_exists('EmbeddedVideoType', $exif) && str_contains($exif['EmbeddedVideoType'], 'MotionPhoto')) {
            return 'self__embeddedvideo';
        }

        // Google JPEG and Samsung HEIC (Apple?)
        if (\array_key_exists('MotionPhoto', $exif)) {
            if ('image/jpeg' === $exif['MIMEType']) {
                // Google JPEG -- image should hopefully be in trailer
                return 'self__trailer';
            }
            if ('image/heic' === $exif['MIMEType']) {
                // Samsung HEIC -- no way to get this out yet
                return '';
            }
        }

        return '';
    }

    public function processVideoPart(File &$file, array &$exif)
    {
        $fileId = $file->getId();
        $mtime = $file->getMTime();
        $liveid = $exif['ContentIdentifier'];
        if (empty($liveid)) {
            return;
        }

        $query = $this->connection->getQueryBuilder();
        $query->select('fileid')
            ->from('memories_livephoto')
            ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
        ;
        $cursor = $query->executeQuery();
        $prevRow = $cursor->fetch();
        $cursor->closeCursor();

        if ($prevRow) {
            // Update existing row
            $query->update('memories_livephoto')
                ->set('liveid', $query->createNamedParameter($liveid, IQueryBuilder::PARAM_STR))
                ->set('mtime', $query->createNamedParameter($mtime, IQueryBuilder::PARAM_INT))
                ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ;
            $query->executeStatement();
        } else {
            // Try to create new row
            try {
                $query->insert('memories_livephoto')
                    ->values([
                        'liveid' => $query->createNamedParameter($liveid, IQueryBuilder::PARAM_STR),
                        'mtime' => $query->createNamedParameter($mtime, IQueryBuilder::PARAM_INT),
                        'fileid' => $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                    ])
                ;
                $query->executeStatement();
            } catch (\Exception $ex) {
                error_log('Failed to create memories_livephoto record: '.$ex->getMessage());
            }
        }
    }
}
