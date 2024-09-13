<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\Exif;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\IDBConnection;

class LivePhoto
{
    public function __construct(private IDBConnection $connection) {}

    /**
     * Check if a given Exif data is the video part of a Live Photo.
     */
    public function isVideoPart(array $exif): bool
    {
        return 'video/quicktime' === ($exif['MIMEType'] ?? null)
               && !empty($exif['ContentIdentifier'] ?? null);
    }

    /** Get liveid from photo part */
    public function getLivePhotoId(File $file, array $exif): string
    {
        // Apple JPEG (MOV has ContentIdentifier)
        if ($uuid = ($exif['ContentIdentifier'] ?? $exif['MediaGroupUUID'] ?? null)) {
            return (string) $uuid;
        }

        // Google MVIMG and Samsung JPEG
        if (($offset = ($exif['MicroVideoOffset'] ?? null)) && ($offset > 0)) {
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
            $videoOffset = $file->getSize() - $offset;

            return "self__traileroffset={$videoOffset}";
        }

        // Google JPEG and Samsung HEIC / JPEG (Apple?)
        if ($exif['MotionPhoto'] ?? null) {
            if ('image/jpeg' === ($exif['MIMEType'] ?? null)) {
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
                //
                // There are cases where Google decided to completely screw up and not include
                // the length for one of the *earlier* DirectoryItemSemantic; in this case we still
                // hope that the video is located at the end, and thus the last DirectoryItemLength
                // seen before the DirectoryItemSemantic of MotionPhoto is the length of the video.
                // https://github.com/pulsejet/memories/issues/965
                $path = $file->getStorage()->getLocalFile($file->getInternalPath())
                    ?: throw new \Exception('[BUG][LivePhoto] Failed to get local file path');
                $extExif = Exif::getExifWithDuplicates($path);
                $lastLength = null; // last DirectoryItemLength seen

                foreach ($extExif as $key => $value) {
                    if (str_ends_with($key, ':DirectoryItemSemantic')) {
                        if ('MotionPhoto' === $value) {
                            // Found the video, try to find the corresponding semantic length
                            // If we can't find it, use the last length seen
                            $videoLength = $extExif[str_replace('Semantic', 'Length', $key)] ?? $lastLength;
                            if (\is_int($videoLength) && $videoLength > 0) {
                                $videoOffset = $file->getSize() - $videoLength;

                                return "self__traileroffset={$videoOffset}";
                            }
                        }
                    }

                    if (str_ends_with($key, ':DirectoryItemLength')) {
                        $lastLength = $value;
                    }
                }

                // Fallback: video should hopefully be in trailer
                return 'self__trailer';
            }

            if ('image/heic' === ($exif['MIMEType'] ?? null)) {
                // Samsung HEIC -- no way to get this out yet (DirectoryItemLength is senseless)
                // The reason this is above the MotionPhotoVideo check is because extracting binary
                // EXIF fields on the fly is extremely expensive compared to trailer extraction.
            }
        }

        // Samsung HEIC (at least S21)
        if (!empty($exif['MotionPhotoVideo'] ?? null)) {
            // It's a binary exif field, decode when the user requests it
            return 'self__exifbin=MotionPhotoVideo';
        }

        return '';
    }

    /**
     * Process video part of Live Photo.
     *
     * This function should be run in a separate transaction.
     */
    public function processVideoPart(File $file, array $exif): bool
    {
        $fileId = $file->getId();
        $mtime = $file->getMTime();
        $liveid = $exif['ContentIdentifier'] ?? null;
        if (empty($liveid)) {
            return false;
        }

        // Check if entry already exists
        $query = $this->connection->getQueryBuilder();
        $exists = $query->select('fileid')
            ->from('memories_livephoto')
            ->where($query->expr()->eq('fileid', $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->executeQuery()
            ->fetch()
        ;

        // Construct query parameters
        $query = $this->connection->getQueryBuilder();
        $params = [
            'liveid' => $query->createNamedParameter($liveid, IQueryBuilder::PARAM_STR),
            'mtime' => $query->createNamedParameter($mtime, IQueryBuilder::PARAM_INT),
            'fileid' => $query->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
            'orphan' => $query->createNamedParameter(false, IQueryBuilder::PARAM_BOOL),
        ];

        // Insert or update
        if ($exists) {
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
