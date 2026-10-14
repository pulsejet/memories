<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

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
}
