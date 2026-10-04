<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCA\Memories\HttpResponseException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class LensFaces
{
    // Keep in sync with the daemon default (lens FACE_VERSION).
    public const FACE_VERSION = 2;
    public const BACKOFF_BASE = 86400;
    public const BACKOFF_MAX = 7776000;

    public function __construct(
        private IDBConnection $connection,
    ) {}

    /**
     * Current face rows of one file, ordered by id.
     *
     * Shape matches the file-metadata faces for restore stability.
     *
     * @return list<array{
     *  id: int, x: float, y: float, w: float, h: float,
     *  det_score: float, cluster_id: ?int, embed_version: int,
     *  next_try: int, retries: int
     * }>
     */
    public function getFacesByFileId(int $fileid): array
    {
        $qb = $this->connection->getQueryBuilder();
        $rows = $qb->select('id', 'x', 'y', 'w', 'h', 'det_score', 'cluster_id', 'embed_version', 'next_try', 'retries')
            ->from('memories_lens_faces')
            ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileid, IQueryBuilder::PARAM_INT)))
            ->addOrderBy('id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        return array_map(self::castFaceRow(...), $rows);
    }

    /**
     * Replace-all for one file: delete every row, recreate with daemon-minted ids.
     *
     * Geometry matching lives in the daemon: it re-provides reused face ids and
     * mints uint63 ids for new faces. PHP trusts the submitted owner and values.
     * Returns the ids for the daemon's vector upsert. Last writer wins.
     *
     * @param mixed $faces raw daemon payload, cast here
     *
     * @return list<array{id: int, cluster_id: ?int}>
     */
    public function replaceFaces(int $fileid, mixed $faces, string $owner): array
    {
        if (!\is_array($faces) || '' === $owner) {
            throw new HttpResponseException(
                new DataResponse(
                    ['message' => 'Invalid faces payload'],
                    Http::STATUS_UNPROCESSABLE_ENTITY,
                ),
            );
        }

        $this->connection->beginTransaction();

        try {
            // Delete-all first: the daemon re-provides every current detection
            // with stable ids, so there is nothing to match or diff.
            $qb = $this->connection->getQueryBuilder();
            $qb->delete('memories_lens_faces')
                ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileid, IQueryBuilder::PARAM_INT)))
                ->executeStatement()
            ;

            // Recreate verbatim: ensure referenced clusters exist, then insert
            // with the daemon-minted ids and zero backoff. Last writer wins.
            $committed = [];
            foreach (array_values($faces) as $face) {
                if (!\is_array($face)) {
                    continue;
                }
                $id = (int) ($face['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $cluster = (int) ($face['cluster_id'] ?? 0);
                $cluster = $cluster > 0 ? $cluster : null;
                if (null !== $cluster) {
                    $this->ensureCluster($cluster, $owner);
                }

                $qb = $this->connection->getQueryBuilder();
                $qb->insert('memories_lens_faces')
                    ->values([
                        'id' => $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT),
                        'fileid' => $qb->createNamedParameter($fileid, IQueryBuilder::PARAM_INT),
                        'x' => $qb->createNamedParameter((float) ($face['x'] ?? 0)),
                        'y' => $qb->createNamedParameter((float) ($face['y'] ?? 0)),
                        'w' => $qb->createNamedParameter((float) ($face['w'] ?? 0)),
                        'h' => $qb->createNamedParameter((float) ($face['h'] ?? 0)),
                        'det_score' => $qb->createNamedParameter((float) ($face['det_score'] ?? 0)),
                        'embed_version' => $qb->createNamedParameter(self::FACE_VERSION, IQueryBuilder::PARAM_INT),
                        'cluster_id' => null !== $cluster ? $qb->createNamedParameter($cluster, IQueryBuilder::PARAM_INT) : $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
                        'next_try' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                        'retries' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                    ])
                    ->executeStatement()
                ;
                $committed[] = ['id' => $id, 'cluster_id' => $cluster];
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        return $committed;
    }

    /**
     * Due unassigned faces, whole files only, ordered by (owner, epoch).
     *
     * Single ordered query capped at $limit rows; when the cap truncates the
     * tail file it is dropped entirely, so grouping never sees a partial file.
     * A lone truncated file is kept: the daemon's own batch caps bound its
     * work and backoff keeps it converging instead of stalling.
     *
     * @return list<array{
     *  id: int, fileid: int, x: float, y: float, w: float, h: float,
     *  det_score: float, embed_version: int, cluster_id: ?int,
     *  next_try: int, retries: int, owner: string, datetaken: int
     * }>
     */
    public function getDueBatch(int $limit = 1000): array
    {
        $limit = min(5000, max(1, $limit));
        $now = time();

        $qb = $this->connection->getQueryBuilder();

        // Faces store no owner: resolve the storage scope per face through
        // filecache -> storages, since grouping never crosses storages and new
        // clusters register under an owner. The inner joins also drop faces of
        // deleted or not-yet-indexed files. Epoch is only for ordering.
        $rows = $qb->select('mlf.id', 'mlf.fileid', 'mlf.x', 'mlf.y', 'mlf.w', 'mlf.h', 'mlf.det_score', 'mlf.cluster_id', 'mlf.embed_version', 'mlf.next_try', 'mlf.retries')
            ->selectAlias('s.id', 'owner')
            ->selectAlias('m.epoch', 'datetaken')
            ->from('memories_lens_faces', 'mlf')
            ->innerJoin('mlf', 'filecache', 'f', $qb->expr()->eq('mlf.fileid', 'f.fileid'))
            ->innerJoin('f', 'storages', 's', $qb->expr()->eq('f.storage', 's.numeric_id'))
            ->innerJoin('mlf', 'memories', 'm', $qb->expr()->eq('mlf.fileid', 'm.fileid'))
            ->where($qb->expr()->isNull('mlf.cluster_id'))
            ->andWhere($qb->expr()->lte('mlf.next_try', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('mlf.embed_version', $qb->createNamedParameter(self::FACE_VERSION, IQueryBuilder::PARAM_INT)))
            ->addOrderBy('s.id', 'ASC')
            ->addOrderBy('m.epoch', 'ASC')
            ->addOrderBy('mlf.fileid', 'ASC')
            ->addOrderBy('mlf.id', 'ASC')
            ->setMaxResults($limit + 1)
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        // One row over the cap tells exact fit apart from truncation.
        $truncated = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);

        // Group rows back into whole files, preserving query order.
        $byFile = [];
        foreach ($rows as $row) {
            $byFile[(int) $row['fileid']][] = $row;
        }

        // A tail file cut by the cap may be partial, so drop it — unless it is
        // the only file, which is kept to guarantee forward progress.
        if ($truncated && \count($byFile) > 1) {
            array_pop($byFile);
        }

        $out = [];
        foreach ($byFile as $fid => $fileRows) {
            foreach ($fileRows as $row) {
                $face = self::castFaceRow($row);
                $face['fileid'] = $fid;
                $face['owner'] = (string) $row['owner'];
                $face['datetaken'] = (int) $row['datetaken'];
                $out[] = $face;
            }
        }

        return $out;
    }

    /**
     * Grouping writeback: assign listed faces to daemon-minted clusters.
     *
     * The daemon submits the batch owner; cluster refs are registered blindly.
     *
     * @param array $assignments daemon rows with id/cluster, cast here
     */
    public function applyClusters(array $assignments, string $owner): int
    {
        $byCluster = [];
        foreach ($assignments as $a) {
            if (!\is_array($a)) {
                continue;
            }
            $id = (int) ($a['id'] ?? 0);
            $cluster = (int) ($a['cluster'] ?? 0);
            if ($id > 0 && $cluster > 0) {
                $byCluster[$cluster][] = $id;
            }
        }

        $assigned = 0;
        foreach ($byCluster as $cluster => $ids) {
            $this->ensureCluster($cluster, $owner);

            foreach (array_chunk($ids, 250) as $batch) {
                $qb = $this->connection->getQueryBuilder();
                $assigned += $qb->update('memories_lens_faces')
                    ->set('cluster_id', $qb->createNamedParameter($cluster, IQueryBuilder::PARAM_INT))
                    ->set('next_try', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                    ->set('retries', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                    ->where($qb->expr()->in('id', $qb->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)))
                    ->executeStatement()
                ;
            }
        }

        return $assigned;
    }

    /**
     * Back off attempted-but-unassigned faces: bump retries and push next_try
     * out on the doubling schedule.
     *
     * @param array $faceIds daemon face ids (or rows carrying id), cast here
     */
    public function backoffFaces(array $faceIds): int
    {
        $ids = [];
        foreach ($faceIds as $id) {
            $id = (int) (\is_array($id) ? ($id['id'] ?? 0) : $id);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        if ([] === $ids) {
            return 0;
        }

        // Read the stored retry counts once; faces assigned since the batch
        // (here or elsewhere) keep their cluster and are left alone.
        $now = time();
        $misses = 0;
        foreach (array_chunk($ids, 250) as $batch) {
            $qb = $this->connection->getQueryBuilder();
            $rows = $qb->select('id', 'retries')
                ->from('memories_lens_faces')
                ->where($qb->expr()->in('id', $qb->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->isNull('cluster_id'))
                ->executeQuery()
                ->fetchAllAssociative()
            ;

            foreach ($rows as $row) {
                $retries = (int) $row['retries'];
                $delay = min(self::BACKOFF_BASE * (1 << min($retries, 20)), self::BACKOFF_MAX);

                $qb = $this->connection->getQueryBuilder();
                $misses += $qb->update('memories_lens_faces')
                    ->set('next_try', $qb->createNamedParameter($now + $delay, IQueryBuilder::PARAM_INT))
                    ->set('retries', $qb->createNamedParameter($retries + 1, IQueryBuilder::PARAM_INT))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter((int) $row['id'], IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->isNull('cluster_id'))
                    ->executeStatement()
                ;
            }
        }

        return $misses;
    }

    public function setPersonName(int $personId, string $viewerId, string $name): void
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 255 || '' === trim($viewerId) || $personId <= 0) {
            throw new HttpResponseException(
                new DataResponse(
                    ['message' => 'Invalid name'],
                    Http::STATUS_UNPROCESSABLE_ENTITY,
                ),
            );
        }

        $qb = $this->connection->getQueryBuilder();
        $updated = $qb->update('memories_lens_persons')
            ->set('name', $qb->createNamedParameter($name))
            ->where($qb->expr()->eq('person_id', $qb->createNamedParameter($personId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('viewer_id', $qb->createNamedParameter($viewerId)))
            ->executeStatement()
        ;

        if (!$updated) {
            $qb = $this->connection->getQueryBuilder();
            $qb->insert('memories_lens_persons')
                ->values([
                    'person_id' => $qb->createNamedParameter($personId, IQueryBuilder::PARAM_INT),
                    'viewer_id' => $qb->createNamedParameter($viewerId),
                    'name' => $qb->createNamedParameter($name),
                ])
                ->executeStatement()
            ;
        }
    }

    public function deleteFileFaces(int $fileid): void
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->delete('memories_lens_faces')
            ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileid, IQueryBuilder::PARAM_INT)))
            ->executeStatement()
        ;
    }

    /**
     * @return array{clusters: int, persons: int}
     */
    public function sweepOrphans(): array
    {
        $faces = $this->connection->getQueryBuilder();
        $faces->select($faces->expr()->literal(1))
            ->from('memories_lens_faces', 'f')
            ->where($faces->expr()->eq('f.cluster_id', '*PREFIX*memories_lens_clusters.id'))
        ;

        $qb = $this->connection->getQueryBuilder();
        $clusters = $qb->delete('memories_lens_clusters')
            ->where(SQL::notExists($qb, $faces))
            ->executeStatement()
        ;

        $refs = $this->connection->getQueryBuilder();
        $refs->select($refs->expr()->literal(1))
            ->from('memories_lens_clusters', 'c')
            ->where($refs->expr()->eq('c.person_id', '*PREFIX*memories_lens_persons.person_id'))
        ;

        $qb = $this->connection->getQueryBuilder();
        $persons = $qb->delete('memories_lens_persons')
            ->where(SQL::notExists($qb, $refs))
            ->executeStatement()
        ;

        return ['clusters' => $clusters, 'persons' => $persons];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: int, x: float, y: float, w: float, h: float, det_score: float, cluster_id: ?int, embed_version: int, next_try: int, retries: int}
     */
    private static function castFaceRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'x' => (float) $row['x'],
            'y' => (float) $row['y'],
            'w' => (float) $row['w'],
            'h' => (float) $row['h'],
            'det_score' => (float) ($row['det_score'] ?? 0),
            'cluster_id' => null !== ($row['cluster_id'] ?? null) ? (int) $row['cluster_id'] : null,
            'embed_version' => (int) ($row['embed_version'] ?? 0),
            'next_try' => (int) ($row['next_try'] ?? 0),
            'retries' => (int) ($row['retries'] ?? 0),
        ];
    }

    private function ensureCluster(int $cluster, string $owner): void
    {
        try {
            $qb = $this->connection->getQueryBuilder();
            $qb->insert('memories_lens_clusters')
                ->values([
                    'id' => $qb->createNamedParameter($cluster, IQueryBuilder::PARAM_INT),
                    'owner_id' => $qb->createNamedParameter($owner),
                    'person_id' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
                    'embed_version' => $qb->createNamedParameter(self::FACE_VERSION, IQueryBuilder::PARAM_INT),
                    'created' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
                ])
                ->executeStatement()
            ;
        } catch (\Throwable) {
            // Concurrent insert wins; the daemon is trusted on scope.
        }
    }
}
