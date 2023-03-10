<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\Exif;
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

    /** Check if a given Exif data is the video part of a Live Photo */
    public function isVideoPart(array &$exif)
    {
        return \array_key_exists('MIMEType', $exif)
               && 'video/quicktime' === $exif['MIMEType']
               && \array_key_exists('ContentIdentifier', $exif);
    }

    /** Get liveid from photo part */
    public function getLivePhotoId(File &$file, array &$exif)
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
                // Google Motion Photo JPEG

                // We need to read the DirectoryItemLength key to get the length of the video
                // These keys are duplicate, one for the image and one for the video
                // With exiftool -G4, we get the following:
                //
                //    "Unknown:DirectoryItemSemantic": "Primary"
                //    "Unknown:DirectoryItemLength": 0
                //    "Copy1:DirectoryItemSemantic": "MotionPhoto"
                //    "Copy1:DirectoryItemLength": 3011435    // <-- this is the length of the video
                //
                // The video is then located at the end of the file, so we can get the offset.
                // Match each DirectoryItemSemantic to find MotionPhoto, then get the length.
                $path = $file->getStorage()->getLocalFile($file->getInternalPath());
                $extExif = Exif::getExifWithDuplicates($path);

                foreach ($extExif as $key => $value) {
                    if (str_ends_with($key, ':DirectoryItemSemantic')) {
                        if ('MotionPhoto' === $value) {
                            $videoLength = $extExif[str_replace('Semantic', 'Length', $key)];
                            if (\is_int($videoLength) && $videoLength > 0) {
                                $videoOffset = $file->getSize() - $videoLength;

                                return 'self__traileroffset='.((string) $videoOffset);
                            }
                        }
                    }
                }

                // Fallback: video should hopefully be in trailer
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
