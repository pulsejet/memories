<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

trait TimelineQueryLivePhoto
{
    public function getLivePhotos(int $fileid): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('lp.fileid', 'lp.liveid')
            ->from('memories', 'm')
            ->where($qb->expr()->eq('m.fileid', $qb->createNamedParameter($fileid)))
            ->innerJoin('m', 'memories_livephoto', 'lp', $qb->expr()->andX(
                $qb->expr()->eq('lp.liveid', 'm.liveid'),
            ))
        ;

        return $qb->executeQuery()->fetchAll();
    }
}
