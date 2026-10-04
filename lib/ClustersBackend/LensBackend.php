<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Varun Patil <radialapps@gmail.com>
 * @author Varun Patil <radialapps@gmail.com>
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\ClustersBackend;

use OCA\Memories\Db\LensFaces;
use OCA\Memories\Db\SQL;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Exceptions;
use OCA\Memories\HttpResponseException;
use OCA\Memories\Service\Lens;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Http\Client\IClientService;
use OCP\IRequest;

final class LensBackend extends Backend
{
    use PeopleBackendUtils;

    public const CLUSTER_TYPE = 'lens';

    public function __construct(
        protected TimelineQuery $tq,
        protected IRequest $request,
        protected Covers $covers,
        protected LensFaces $lensFaces,
        protected Lens $lens,
        protected IClientService $clientService,
        protected Util $util,
    ) {}

    #[\Override]
    public static function appName(): string
    {
        return 'Lens';
    }

    #[\Override]
    public static function clusterType(): string
    {
        return self::CLUSTER_TYPE;
    }

    #[\Override]
    public function isEnabled(): bool
    {
        return '' !== trim($this->lens->daemonUrl());
    }

    #[\Override]
    public function transformDayQuery(IQueryBuilder &$query, bool $aggregate): void
    {
        // Check if Lens is enabled
        if (!$this->isEnabled()) {
            throw Exceptions::NotEnabled('Lens');
        }

        $faceStr = (string) $this->request->getParam('lens');
        $faceNames = explode('/', $faceStr);
        if (2 !== \count($faceNames)) {
            throw new \Exception('Invalid face query');
        }

        // The uid part is retained for API consistency with the other
        // people backends; scoping always uses the requesting viewer.
        [$faceUid, $faceName] = $faceNames;

        if (!$aggregate) {
            // Multiple detections for the same image
            $query->selectAlias('mlf.id', 'faceid');

            // Face Rect (fractions, same convention as Recognize)
            if ($this->request->getParam('facerect')) {
                $query->selectAlias('mlf.w', 'face_w')
                    ->selectAlias('mlf.h', 'face_h')
                    ->selectAlias('mlf.x', 'face_x')
                    ->selectAlias('mlf.y', 'face_y')
                ;
            }
        }

        // Join with faces of this file
        $query->innerJoin('m', 'memories_lens_faces', 'mlf', $query->expr()->eq('mlf.fileid', 'm.fileid'));

        // Restrict to the requested person or cluster
        if ('NULL' === $faceName) {
            $query->andWhere($query->expr()->isNull('mlf.cluster_id'));
        } else {
            $clusterIds = $this->resolveClusters($faceName);
            if ([] === $clusterIds) {
                throw Exceptions::NotFound('person');
            }
            $query->andWhere($query->expr()->in('mlf.cluster_id', $query->createNamedParameter($clusterIds, IQueryBuilder::PARAM_INT_ARRAY)));
        }
    }

    #[\Override]
    public function transformDayPost(array &$row): void
    {
        // Face ids are uint63: serialize as strings, JS floats lose precision.
        if (isset($row['faceid'])) {
            $row['faceid'] = (string) $row['faceid'];
        }

        // Differentiate Lens queries from Face Recognition
        if (!isset($row['face_w'])) {
            return;
        }

        // Convert face rect to object (already fractions)
        $row['facerect'] = [
            'w' => (float) $row['face_w'],
            'h' => (float) $row['face_h'],
            'x' => (float) $row['face_x'],
            'y' => (float) $row['face_y'],
        ];

        unset($row['face_w'], $row['face_h'], $row['face_x'], $row['face_y']);
    }

    #[\Override]
    public function getClustersInternal(int $fileid = 0): array
    {
        $uid = $this->util->getUID();
        $query = $this->tq->getBuilder();

        // SELECT persons grouped by owning cluster: named persons are all
        // clusters sharing one person_id, unnamed ones are single clusters.
        // The person key is wrapped as a function so the builder quotes
        // neither the expression nor its dots as identifiers. select()
        // resets the select list, so it goes first and selectAlias appends.
        $count = $query->func()->count(SQL::distinct($query, 'm.fileid'), 'count');
        $query->select($count);
        $query->selectAlias($query->createFunction('COALESCE(mlc.person_id, mlc.id)'), 'pid');
        $query->selectAlias($query->func()->max('mlp.name'), 'name');
        $query->selectAlias($query->expr()->literal($uid), 'user_id');
        $query->from('memories_lens_clusters', 'mlc');

        // WHERE there are faces with this cluster
        $query->innerJoin('mlc', 'memories_lens_faces', 'mlf', $query->expr()->eq('mlf.cluster_id', 'mlc.id'));

        // WHERE these items are memories indexed photos
        $query->innerJoin('mlf', 'memories', 'm', $query->expr()->eq('m.fileid', 'mlf.fileid'));

        // WHERE the viewer named this person, if they did. The person key
        // is wrapped as a function so the builder does not quote it.
        $personKey = $query->createFunction('COALESCE(mlc.person_id, mlc.id)');
        $query->leftJoin('mlc', 'memories_lens_persons', 'mlp', $query->expr()->andX(
            $query->expr()->eq('mlp.person_id', $personKey),
            $query->expr()->eq('mlp.viewer_id', $query->createNamedParameter($uid)),
        ));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->filterFilecache($query);

        // WHERE these clusters contain fileid if specified
        if ($fileid > 0) {
            $query->andWhere($query->expr()->eq('mlf.fileid', $query->createNamedParameter($fileid, \PDO::PARAM_INT)));
        }

        // GROUP by owning cluster of the person (function passes through unquoted)
        $query->addGroupBy($query->createFunction('COALESCE(mlc.person_id, mlc.id)'));

        // SELECT to get all covers, keyed by person (see selectPersonCover)
        $query = SQL::materialize($query, 'mlc');
        $this->selectPersonCover($query);

        // SELECT etag for the cover
        // Since the "cover" is the face detection, we need the actual file for etag
        $query = SQL::materialize($query, 'mlc');
        $cfSq = $this->tq->getBuilder();
        $cfSq->select('fileid')
            ->from('memories_lens_faces', 'mlf')
            ->where($cfSq->expr()->eq('mlf.id', 'mlc.cover'))
            ->setMaxResults(1)
        ;
        $this->tq->selectEtag($query, SQL::subquery($query, $cfSq), 'cover_etag');

        // FETCH all faces
        $faces = $this->tq->executeQueryWithCTEs($query)->fetchAllAssociative();

        // Post process: named persons first, then by size.
        // Ids are uint63: serialize as strings, JS floats lose precision.
        foreach ($faces as &$row) {
            $row['id'] = $row['name'] ?? (string) $row['pid'];
            $row['count'] = (int) $row['count'];
            if (null !== $row['cover']) {
                $row['cover'] = (string) $row['cover'];
            }
            unset($row['pid']);
        }
        unset($row);

        usort($faces, static fn ($a, $b) => [empty($a['name']), -$a['count'], $a['id']] <=> [empty($b['name']), -$b['count'], $b['id']]);

        return $faces;
    }

    #[\Override]
    public static function getClusterId(array $cluster): int|string
    {
        return $cluster['id'];
    }

    #[\Override]
    public function getPhotos(string $name, ?int $limit = null, ?int $fileid = null): array
    {
        $clusterIds = $this->resolveClusters($name);
        if ([] === $clusterIds) {
            return [];
        }

        $query = $this->tq->getBuilder();

        // SELECT face detections for the person
        $query->select(
            'mlf.id AS faceid',
            'mlf.cluster_id',
            'mlf.x',                    // Image cropping (fractions)
            'mlf.y',
            'mlf.w AS width',
            'mlf.h AS height',
            'm.w as image_width',       // Scoring
            'm.h as image_height',
            'm.fileid',
            'm.datetaken',              // Just in case, for postgres
        )->from('memories_lens_faces', 'mlf');

        // WHERE detection belongs to one of the person's clusters
        $query->where($query->expr()->in('mlf.cluster_id', $query->createNamedParameter($clusterIds, IQueryBuilder::PARAM_INT_ARRAY)));

        // WHERE these photos are memories indexed
        $query->innerJoin('mlf', 'memories', 'm', $query->expr()->eq('m.fileid', 'mlf.fileid'));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->filterFilecache($query);

        // LIMIT results
        if (-6 === $limit) {
            $this->filterPersonCover($query, $clusterIds);
        } elseif (null !== $limit) {
            $query->setMaxResults($limit);
        }

        // Filter by fileid if specified
        if (null !== $fileid) {
            $query->andWhere($query->expr()->eq('mlf.fileid', $query->createNamedParameter($fileid, IQueryBuilder::PARAM_INT)));
        }

        // Sort by date taken so we get recent photos
        $query->addOrderBy('m.datetaken', 'DESC');
        $query->addOrderBy('m.fileid', 'DESC'); // tie-breaker

        // FETCH face detections, stringifying uint63 ids for JS
        $photos = $this->tq->executeQueryWithCTEs($query)->fetchAllAssociative();
        foreach ($photos as &$photo) {
            $photo['faceid'] = (string) $photo['faceid'];
            $photo['cluster_id'] = (string) $photo['cluster_id'];
        }
        unset($photo);

        return $photos;
    }

    #[\Override]
    public function sortPhotosForPreview(array &$photos): void
    {
        $this->sortByScores($photos);
    }

    #[\Override]
    public function getPreviewBlob(ISimpleFile $file, array $photo): array
    {
        return $this->cropFace($file, $photo, 1.5);
    }

    #[\Override]
    public function getPreviewQuality(): int
    {
        return 2048;
    }

    #[\Override]
    public function getCoverObjId(array $photo): int
    {
        return (int) $photo['faceid'];
    }

    #[\Override]
    public function getClusterIdFrom(array $photo): int
    {
        return (int) $photo['cluster_id'];
    }

    #[\Override]
    public function setCover(array $photo, bool $manual = false): void
    {
        // Covers attach to the person (owning cluster), never to the
        // member cluster the face happens to sit in.
        $cluster = $this->lensFaces->getCluster((int) $photo['cluster_id']);
        if (null === $cluster) {
            throw Exceptions::NotFound('cluster');
        }

        $this->covers->setCover(
            self::clusterType(),
            $cluster['person_id'] ?? $cluster['id'],
            (int) $photo['faceid'],
            (int) $photo['fileid'],
            $manual,
        );
    }

    /**
     * Resolve a route name to in-scope cluster ids of one person.
     *
     * Numeric names address a cluster but always expand to its whole
     * person; other names address the viewer's named person. A merged-away
     * cluster has no independent identity. Out-of-scope and unknown names
     * resolve empty.
     *
     * @return list<int>
     */
    public function resolveClusters(string $name): array
    {
        if ('NULL' === $name) {
            return [];
        }

        if (is_numeric($name)) {
            $cluster = $this->lensFaces->getCluster((int) $name);
            if (null === $cluster) {
                return [];
            }

            $scoped = $this->scopedClusterIds(
                $this->lensFaces->clustersOfPerson($cluster['person_id'] ?? $cluster['id']),
            );
            if ([] !== $scoped) {
                return $scoped;
            }

            // Freshly created and still empty: only its owner's viewer may
            // use it (e.g. moving the first faces in). Reads stay empty
            // either way, so nothing leaks through this.
            if ([] === $this->lensFaces->faceIdsOfClusters([$cluster['id']])
                && 'home::'.$this->util->getUID() === $cluster['owner_id']) {
                return [$cluster['id']];
            }

            return [];
        }

        $personId = $this->lensFaces->findPersonId($this->util->getUID(), $name);
        if (null === $personId) {
            return [];
        }

        return $this->scopedClusterIds($this->lensFaces->clustersOfPerson($personId));
    }

    /**
     * Rename a cluster or person to a viewer-scoped name.
     *
     * Unnamed clusters become self-owned persons; renaming a merged person
     * renames all of its clusters together.
     *
     * @throws \OCA\Memories\HttpResponseException
     */
    public function renamePerson(string $name, string $newName): string
    {
        $clusters = $this->resolveClusters($name);
        if ([] === $clusters) {
            throw Exceptions::NotFound('person');
        }

        // The person is owned by its lowest cluster; a detached cluster
        // owns itself. Merged clusters keep pointing at their person.
        $personId = $this->personOf($clusters);
        if (null === $personId) {
            $personId = min($clusters);
            $this->lensFaces->setClustersPerson($clusters, $personId);
        }

        $this->lensFaces->setPersonName($personId, $this->util->getUID(), $newName);

        return $newName;
    }

    /**
     * Merge the source person or cluster into the target person or cluster.
     *
     * @return string display name of the surviving person
     *
     * @throws \OCA\Memories\HttpResponseException
     */
    public function mergePersons(string $source, string $target): string
    {
        $sourceClusters = $this->resolveClusters($source);
        $targetClusters = $this->resolveClusters($target);
        if ([] === $sourceClusters || [] === $targetClusters) {
            throw Exceptions::NotFound('person');
        }

        $targetPerson = $this->personOf($targetClusters);
        if (null === $targetPerson) {
            $targetPerson = min($targetClusters);
            $this->lensFaces->setClustersPerson($targetClusters, $targetPerson);
        }

        $this->lensFaces->setClustersPerson($sourceClusters, $targetPerson);
        $this->lensFaces->sweepOrphans();

        $name = $this->personName($targetPerson);

        return $name ?? (string) $targetPerson;
    }

    /**
     * Create an empty person with a server-minted id, optionally named.
     *
     * Ids are uint63: minted here because JS floats cannot hold them.
     *
     * @return array{id: string, name: string}
     *
     * @throws \OCA\Memories\HttpResponseException
     */
    public function createPerson(string $name = ''): array
    {
        $id = 0;
        for ($i = 0; $i < 10 && $id <= 0; ++$i) {
            $candidate = random_int(1, PHP_INT_MAX);
            if (null === $this->lensFaces->getCluster($candidate)) {
                $id = $candidate;
            }
        }

        if ($id <= 0) {
            throw new HttpResponseException(
                new \OCP\AppFramework\Http\DataResponse(
                    ['message' => 'Failed to mint cluster id'],
                    \OCP\AppFramework\Http::STATUS_SERVICE_UNAVAILABLE,
                ),
            );
        }

        $uid = $this->util->getUID();
        $this->lensFaces->createCluster($id, 'home::'.$uid);

        $name = trim($name);
        if ('' !== $name) {
            $this->lensFaces->setClustersPerson([$id], $id);
            $this->lensFaces->setPersonName($id, $uid, $name);
        }

        return ['id' => (string) $id, 'name' => $name];
    }

    /**
     * Move faces to a person or cluster (null unassigns).
     *
     * A numeric target addressing no cluster is a client-minted id: the
     * cluster is created under the faces' storage scope, like recognize
     * creating a folder. Existing clusters are never adopted blindly, so
     * out-of-scope ids stay unreachable.
     *
     * @param array $faceIds raw face ids, cast here
     *
     * @return list<int> moved face ids
     *
     * @throws \OCA\Memories\HttpResponseException
     */
    public function moveFaces(array $faceIds, ?string $target): array
    {
        $scoped = $this->scopedFaceIds($faceIds);
        if ([] === $scoped) {
            throw Exceptions::NotFound('faces');
        }

        if (null === $target || 'NULL' === $target) {
            $this->syncFaceClusters($scoped, null);
            $this->lensFaces->moveFaces($scoped, null, '');

            return $scoped;
        }

        $clusters = $this->resolveClusters($target);
        if ([] !== $clusters) {
            // A named person receives faces on its owning cluster.
            $clusterId = $this->personOf($clusters) ?? min($clusters);
            $cluster = $this->lensFaces->getCluster($clusterId);
            $this->syncFaceClusters($scoped, $clusterId);
            $this->lensFaces->moveFaces($scoped, $clusterId, $cluster['owner_id'] ?? '');

            return $scoped;
        }

        if (!is_numeric($target) || (int) $target <= 0) {
            throw Exceptions::NotFound('person');
        }

        // Refuse to adopt an existing cluster the viewer cannot see.
        $clusterId = (int) $target;
        if (null !== $this->lensFaces->getCluster($clusterId)) {
            throw Exceptions::NotFound('person');
        }

        $owner = $this->storageOfFace($scoped[0]);
        $this->syncFaceClusters($scoped, $clusterId);
        $this->lensFaces->createCluster($clusterId, $owner);
        $this->lensFaces->moveFaces($scoped, $clusterId, $owner);

        return $scoped;
    }

    /**
     * Remove a person: unassign every face of its clusters and drop the
     * viewer's name. Empty clusters and persons are swept.
     *
     * @return array{faces: int, clusters: int}
     *
     * @throws \OCA\Memories\HttpResponseException
     */
    public function removePerson(string $name): array
    {
        $clusters = $this->resolveClusters($name);
        if ([] === $clusters) {
            throw Exceptions::NotFound('person');
        }

        $personId = $this->personOf($clusters) ?? min($clusters);
        $faceIds = $this->lensFaces->faceIdsOfClusters($this->lensFaces->clustersOfPerson($personId));
        $this->syncFaceClusters($faceIds, null);
        $cleared = $this->lensFaces->clearPerson($personId, $this->util->getUID());
        $this->lensFaces->sweepOrphans();

        return $cleared;
    }

    /**
     * Push moved faces to the daemon synchronously before SQL commits.
     *
     * All validation happens before this call, so a failure here aborts
     * the move with nothing changed. Without this the next grouping pass
     * would sample the faces under their stale cluster.
     *
     * @param list<int> $faceIds
     *
     * @throws \OCA\Memories\HttpResponseException
     */
    private function syncFaceClusters(array $faceIds, ?int $clusterId): void
    {
        if ([] === $faceIds) {
            return;
        }

        $base = $this->lens->daemonUrl();
        if ('' === trim($base)) {
            throw Exceptions::NotEnabled('Lens');
        }

        try {
            $this->clientService->newClient()->post(rtrim($base, '/').'/v1/faces/reassign', [
                'json' => [
                    'faces' => array_map(static fn ($id) => ['id' => $id, 'cluster' => $clusterId], $faceIds),
                ],
                'timeout' => 30,
                'nextcloud' => ['allow_local_address' => true],
            ]);
        } catch (\Exception $e) {
            throw new HttpResponseException(
                new \OCP\AppFramework\Http\DataResponse(
                    ['message' => 'Lens daemon unreachable'],
                    \OCP\AppFramework\Http::STATUS_SERVICE_UNAVAILABLE,
                ),
            );
        }
    }

    /**
     * Select the person-keyed cover for each row, like Covers::selectCover
     * but validating against any member cluster of the person.
     *
     * Covers are stored with clusterid set to the person key (owning
     * cluster id), so merges and moves never orphan them.
     */
    private function selectPersonCover(IQueryBuilder &$query): void
    {
        $uid = $this->util->getUID();

        // The cover face must currently belong to the person, whichever
        // member cluster it sits in.
        $validSq = $query->getConnection()->getQueryBuilder();
        $validSq->select($validSq->expr()->literal(1))
            ->from('memories_lens_faces', 'cov_face')
            ->innerJoin('cov_face', 'memories_lens_clusters', 'cov_clu', $validSq->expr()->eq('cov_clu.id', 'cov_face.cluster_id'))
            ->where($validSq->expr()->eq('cov_face.id', 'mcov.objectid'))
            ->andWhere($validSq->expr()->orX(
                $validSq->expr()->eq('cov_clu.person_id', 'mlc.pid'),
                $validSq->expr()->eq('cov_clu.id', 'mlc.pid'),
            ))
        ;

        // The cover file must still be in the user's timeline tree.
        $treeSq = $query->getConnection()->getQueryBuilder();
        $treeSq->select($treeSq->expr()->literal(1))
            ->from('filecache', 'cov_f')
            ->innerJoin('cov_f', 'cte_folders', 'cov_cte_f', $treeSq->expr()->andX(
                $treeSq->expr()->eq('cov_cte_f.fileid', 'cov_f.parent'),
                $treeSq->expr()->eq('cov_cte_f.hidden', SQL::literal($treeSq, 0, \PDO::PARAM_INT)),
            ))
            ->where($treeSq->expr()->eq('cov_f.fileid', 'mcov.fileid'))
        ;

        $cvQ = $query->getConnection()->getQueryBuilder();
        $cvQ->select('mcov.objectid')
            ->from('memories_covers', 'mcov')
            ->where($cvQ->expr()->eq('mcov.uid', $cvQ->expr()->literal($uid)))
            ->andWhere($cvQ->expr()->eq('mcov.clustertype', $cvQ->expr()->literal(self::clusterType())))
            ->andWhere($cvQ->expr()->eq('mcov.clusterid', 'mlc.pid'))
            ->andWhere(SQL::exists($query, $validSq))
            ->andWhere(SQL::exists($query, $treeSq))
            ->setMaxResults(1)
        ;

        $query->selectAlias(SQL::subquery($query, $cvQ), 'cover');
    }

    /**
     * Restrict a photos query to the person-keyed cover, like
     * Covers::filterCover but matching any member cluster of the person.
     *
     * @param list<int> $clusterIds clusters of the person
     */
    private function filterPersonCover(IQueryBuilder &$query, array $clusterIds): void
    {
        // Covers are keyed by person, which is not necessarily a member.
        $pids = [];
        foreach ($clusterIds as $id) {
            $cluster = $this->lensFaces->getCluster($id);
            if (null !== $cluster) {
                $pids[] = $cluster['person_id'] ?? $cluster['id'];
            }
        }
        $pids = array_values(array_unique($pids));
        if ([] === $pids) {
            $pids = $clusterIds;
        }

        $query->innerJoin('mlf', 'memories_covers', 'm_cov', $query->expr()->andX(
            $query->expr()->eq('m_cov.uid', $query->expr()->literal($this->util->getUID())),
            $query->expr()->eq('m_cov.clustertype', $query->expr()->literal(self::clusterType())),
            $query->expr()->eq('m_cov.objectid', $query->expr()->castColumn('mlf.id', IQueryBuilder::PARAM_INT)),
            $query->expr()->in('m_cov.clusterid', $query->createNamedParameter($pids, IQueryBuilder::PARAM_INT_ARRAY)),
        ));
    }

    /**
     * Viewer-scoped display name of a person, if the viewer named it.
     */
    private function personName(int $personId): ?string
    {
        $uid = $this->util->getUID();
        $qb = $this->tq->getBuilder();
        $name = $qb->select('name')
            ->from('memories_lens_persons')
            ->where($qb->expr()->eq('person_id', $qb->createNamedParameter($personId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('viewer_id', $qb->createNamedParameter($uid)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne()
        ;

        return false !== $name ? (string) $name : null;
    }

    /**
     * Storage scope of one face's file, if still present.
     */
    private function storageOfFace(int $faceId): string
    {
        $query = $this->tq->getBuilder();
        $owner = $query->select('s.id')
            ->from('memories_lens_faces', 'mlf')
            ->innerJoin('mlf', 'filecache', 'f', $query->expr()->eq('f.fileid', 'mlf.fileid'))
            ->innerJoin('f', 'storages', 's', $query->expr()->eq('s.numeric_id', 'f.storage'))
            ->where($query->expr()->eq('mlf.id', $query->createNamedParameter($faceId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne()
        ;

        return false !== $owner ? (string) $owner : '';
    }

    /**
     * Person owning these clusters, if any of them is attached to one.
     *
     * @param list<int> $clusterIds
     */
    private function personOf(array $clusterIds): ?int
    {
        $persons = [];
        foreach ($clusterIds as $id) {
            $cluster = $this->lensFaces->getCluster($id);
            if (null !== $cluster && null !== $cluster['person_id']) {
                $persons[] = $cluster['person_id'];
            }
        }

        if ([] === $persons) {
            return null;
        }

        sort($persons);

        return $persons[0];
    }

    /**
     * Intersect face ids with the user's timeline scope.
     *
     * @param array $faceIds raw face ids, cast here
     *
     * @return list<int>
     */
    private function scopedFaceIds(array $faceIds): array
    {
        $ids = [];
        foreach ($faceIds as $id) {
            $id = (int) (\is_array($id) ? ($id['id'] ?? 0) : $id);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        if ([] === $ids) {
            return [];
        }

        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 250) as $batch) {
            $query = $this->tq->getBuilder();
            $query->select('mlf.id')
                ->from('memories_lens_faces', 'mlf')
                ->innerJoin('mlf', 'memories', 'm', $query->expr()->eq('m.fileid', 'mlf.fileid'))
                ->where($query->expr()->in('mlf.id', $query->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)))
            ;
            $query = $this->tq->filterFilecache($query);
            $rows = $this->tq->executeQueryWithCTEs($query)->fetchAllAssociative();
            foreach ($rows as $row) {
                $out[] = (int) $row['id'];
            }
        }

        return $out;
    }

    /**
     * Clusters having at least one face in the user's timeline scope.
     *
     * @param list<int> $clusterIds
     *
     * @return list<int>
     */
    private function scopedClusterIds(array $clusterIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $clusterIds), static fn ($id) => $id > 0)));
        if ([] === $ids) {
            return [];
        }

        $out = [];
        foreach (array_chunk($ids, 250) as $batch) {
            $query = $this->tq->getBuilder();
            $query->selectDistinct('mlf.cluster_id')
                ->from('memories_lens_faces', 'mlf')
                ->innerJoin('mlf', 'memories', 'm', $query->expr()->eq('m.fileid', 'mlf.fileid'))
                ->where($query->expr()->in('mlf.cluster_id', $query->createNamedParameter($batch, IQueryBuilder::PARAM_INT_ARRAY)))
            ;
            $query = $this->tq->filterFilecache($query);
            $rows = $this->tq->executeQueryWithCTEs($query)->fetchAllAssociative();
            foreach ($rows as $row) {
                $out[] = (int) $row['cluster_id'];
            }
        }

        return $out;
    }
}
