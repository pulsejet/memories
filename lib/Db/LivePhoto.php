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
    public function isVideoPart(array $exif)
    {
        return \array_key_exists('MIMEType', $exif)
               && 'video/quicktime' === $exif['MIMEType']
               && \array_key_exists('ContentIdentifier', $exif);
    }

    /** Get liveid from photo part */
    public function getLivePhotoId(File $file, array $exif)
    {
        // Apple JPEG (MOV has ContentIdentifier)
        if (\array_key_exists('MediaGroupUUID', $exif)) {
            return $exif['MediaGroupUUID'];
        }

        // Google MVIMG and Samsung JPEG
        if (\array_key_exists('MicroVideoOffset', $exif) && ($videoLength = $exif['MicroVideoOffset']) > 0) {
            // As explained in the following issue,
            // https://github.com/pulsejet/memories/issues/468
            //
            // MicroVideoOffset is the length of the video in bytes
            // and the video is located at the end of the file.
            //
            // Note that we could have just used "self__trailer" here,
            // since exiftool can extract the video from the trailer,
            // but explicitly specifying the offset is much faster because
            // we don't need to spawn exiftool to read the video file.
            //
            // For Samsung JPEG, we can also check for EmbeddedVideoType
            // and subsequently extract the video file using the
            // EmbeddedVideoFile binary prop, but setting the offset
            // is faster for the same reason mentioned above.
            $videoOffset = $file->getSize() - $videoLength;

            return "self__traileroffset={$videoOffset}";
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

                                return "self__traileroffset={$videoOffset}";
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

    /**
     * Process video part of Live Photo.
     */
    public function processVideoPart(File $file, array $exif): bool
    {
        $fileId = $file->getId();
        $mtime = $file->getMTime();
        $liveid = $exif['ContentIdentifier'];
        if (empty($liveid)) {
            return false;
        }

        $query = $this->connection->getQueryBuilder();
        $query->select('fileid')
            ->from('memories_livephoto')
            ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
        ;
        $prevRow = $query->executeQuery()->fetch();

        $params = [
            'liveid' => $query->createNamedParameter($liveid, IQueryBuilder::PARAM_STR),
            'mtime' => $query->createNamedParameter($mtime, IQueryBuilder::PARAM_INT),
            'fileid' => $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
            'orphan' => $query->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
        ];

        try {
            if ($prevRow) {
                $query->update('memories_livephoto')
                    ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
                ;
                foreach ($params as $key => $value) {
                    $query->set($key, $value);
                }
            } else {
                $query->insert('memories_livephoto')->values($params);
            }

            return $query->executeStatement() > 0;
        } catch (\Exception $ex) {
            throw new \Exception('Failed to create livephoto record: '.$ex->getMessage());
        }
    }

    /**
     * Delete entry from memories_livephoto table.
     */
    public function deleteVideoPart(File $file): void
    {
        $query = $this->connection->getQueryBuilder();
        $query->delete('memories_livephoto')
            ->where($query->expr()->eq('fileid', $query->createNamedParameter($file->getId(), IQueryBuilder::PARAM_INT)))
        ;
        $query->executeStatement();
    }
}
