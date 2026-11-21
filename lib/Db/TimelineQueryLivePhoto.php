<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\Files\File;

trait TimelineQueryLivePhoto
{
    use TimelineQueryBase;

    public function getLivePhotos(int $fileid): array
    {
        $qb = $this->connection->getQueryBuilder();

        return $qb->select('lp.fileid', 'lp.liveid')
            ->from('memories', 'm')
            ->where($qb->expr()->eq('m.fileid', $qb->createNamedParameter($fileid)))
            ->innerJoin('m', 'memories_livephoto', 'lp', $qb->expr()->andX(
                $qb->expr()->eq('lp.liveid', 'm.liveid'),
            ))
            ->executeQuery()
            ->fetchAllAssociative()
        ;
    }

    /**
     * Get the live photo ID for a file, if indexed.
     *
     * @return ?string liveid or null if not a live photo
     */
    public function getLiveId(int $fileid): ?string
    {
        $qb = $this->connection->getQueryBuilder();
        $liveId = $qb->select('liveid')
            ->from('memories')
            ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileid, \PDO::PARAM_INT)))
            ->executeQuery()
            ->fetchOne()
        ;

        return (string) $liveId ?: null;
    }

    /**
     * Get the closest live video to the given file.
     */
    public function getClosestLiveVideo(File $file): ?File
    {
        // Get stored video file (Apple MOV)
        $liveRecords = $this->getLivePhotos($file->getId());

        // Get file paths for all live photos
        $liveFiles = array_map(fn ($r) => $this->fsManager->getUserFileOrNull((int) $r['fileid']), $liveRecords);
        $liveFiles = array_filter($liveFiles, static fn ($f) => $f instanceof File);

        // Should be filtered enough by now
        if (!\count($liveFiles)) {
            return null;
        }

        // All paths including the image and videos need to be processed
        $paths = array_map(static function (File $file) {
            $path = $file->getPath();
            $filename = strtolower($file->getName());

            // Remove extension so the filename itself counts in the path
            if (str_contains($filename, '.')) {
                $filename = substr($filename, 0, strrpos($filename, '.') ?: null);
            }

            // Get components with the filename as lowercase
            $components = explode('/', $path);
            if (($l = \count($components)) > 0) {
                $components[$l - 1] = $filename;
            }

            return $components;
        }, array_merge($liveFiles, [$file]));

        // Find closest path match
        $imagePath = array_pop($paths);
        $scores = array_map(static function ($path) use ($imagePath) {
            $score = 0;
            $length = min(\count($path), \count($imagePath));
            for ($i = 0; $i < $length; ++$i) {
                if ($path[$i] === $imagePath[$i]) {
                    $score += 10000; // Exact match bonus
                } else {
                    $score -= \count($path) - $i; // Walk down penalty

                    break;
                }
            }

            return $score;
        }, $paths);

        // Sort by score
        array_multisort($scores, SORT_ASC, $liveFiles);

        return array_pop($liveFiles);
    }
}
