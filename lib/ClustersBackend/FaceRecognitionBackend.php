<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Varun Patil <radialapps@gmail.com>
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

use OCA\Memories\Db\SQL;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Backend for the Face Recognition app.
 *
 * Schema note: a face no longer points at a person directly. The chain is
 *
 *     facerecog_faces.cluster  -> facerecog_clusters.id
 *     facerecog_clusters.person -> facerecog_persons.id  (NULL while unnamed)
 *
 * so facerecog_persons holds only named people, and the unnamed groupings
 * live in facerecog_clusters. Everything below joins through that extra hop.
 */
final class FaceRecognitionBackend extends Backend
{
    use PeopleBackendUtils;

    public function __construct(
        protected IRequest $request,
        protected TimelineQuery $tq,
        protected IAppConfig $appConfig,
    ) {}

    #[\Override]
    public static function appName(): string
    {
        return 'Face Recognition';
    }

    #[\Override]
    public static function clusterType(): string
    {
        return 'facerecognition';
    }

    #[\Override]
    public function isEnabled(): bool
    {
        return Util::facerecognitionIsInstalled()
               && Util::facerecognitionIsEnabled();
    }

    #[\Override]
    public function transformDayQuery(IQueryBuilder &$query, bool $aggregate): void
    {
        $personStr = (string) $this->request->getParam('facerecognition');

        // Get title and uid of face user
        $personNames = explode('/', $personStr);
        if (2 !== \count($personNames)) {
            throw new \Exception('Invalid person query');
        }
        [$personUid, $personName] = $personNames;

        // Join with images
        $query->innerJoin('m', 'facerecog_images', 'fri', $query->expr()->andX(
            $query->expr()->eq('fri.file', 'm.fileid'),
            $query->expr()->eq('fri.model', $query->createNamedParameter($this->model())),
        ));

        // Join with faces
        $query->innerJoin('fri', 'facerecog_faces', 'frf', $query->expr()->eq('frf.image', 'fri.id'));

        // Join with clusters: every face belongs to at most one cluster
        $query->innerJoin('frf', 'facerecog_clusters', 'frc', $query->expr()->eq('frc.id', 'frf.cluster'));

        if (is_numeric($personName)) {
            // An unnamed cluster is addressed by its numeric id
            $query->andWhere($query->expr()->andX(
                $query->expr()->eq('frc.id', $query->createNamedParameter($personName, \PDO::PARAM_INT)),
                $query->expr()->eq('frc.user', $query->createNamedParameter($personUid)),
            ));
        } else {
            // A named person is addressed by name, through the cluster
            $query->innerJoin('frc', 'facerecog_persons', 'frp', $query->expr()->andX(
                $query->expr()->eq('frp.id', 'frc.person'),
                $query->expr()->eq('frp.user', $query->createNamedParameter($personUid)),
                $query->expr()->eq('frp.name', $query->createNamedParameter($personName)),
            ));
        }

        if (!$aggregate) {
            // Multiple detections for the same image
            $query->selectAlias('frf.id', 'faceid');

            // Face Rect
            if ($this->request->getParam('facerect')) {
                $query->selectAlias('frf.x', 'face_x')
                    ->selectAlias('frf.y', 'face_y')
                    ->selectAlias('frf.width', 'face_width')
                    ->selectAlias('frf.height', 'face_height')
                    ->selectAlias('m.w', 'image_width')
                    ->selectAlias('m.h', 'image_height')
                ;
            }
        }
    }

    #[\Override]
    public function transformDayPost(array &$row): void
    {
        // Differentiate Recognize queries from Face Recognition
        if (!isset($row['face_width']) || !isset($row['image_width'])) {
            return;
        }

        // Get percentage position and size
        $row['facerect'] = [
            'w' => (float) $row['face_width'] / $row['image_width'],
            'h' => (float) $row['face_height'] / $row['image_height'],
            'x' => (float) $row['face_x'] / $row['image_width'],
            'y' => (float) $row['face_y'] / $row['image_height'],
        ];

        unset($row['face_x'], $row['face_y'], $row['face_width'], $row['face_height'], $row['image_height'], $row['image_width']);
    }

    #[\Override]
    public function getClustersInternal(int $fileid = 0): array
    {
        $faces = array_merge(
            $this->getFaceRecognitionPersons($fileid),
            $this->getFaceRecognitionClusters($fileid),
        );

        // Post process
        foreach ($faces as &$row) {
            $row['id'] = $row['name'] ?? (int) $row['id'];
            $row['count'] = (int) $row['count'];
        }

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
        $query = $this->tq->getBuilder();

        // A numeric name is the id of an unnamed cluster; anything else is a
        // person name that has to be resolved through facerecog_persons.
        $isClusterId = is_numeric($name);

        // SELECT face detections
        $query->select(
            'frf.id as faceid',         // Face ID
            'fri.file as file_id',      // Get actual file
            'frf.x',                    // Image cropping
            'frf.y',
            'frf.width',
            'frf.height',
            'm.w as image_width',       // Scoring
            'm.h as image_height',
            'm.fileid',
            'm.datetaken',              // Just in case, for postgres
        )->from('facerecog_faces', 'frf');

        // WHERE faces are from images and current model.
        $query->innerJoin('frf', 'facerecog_images', 'fri', $query->expr()->andX(
            $query->expr()->eq('fri.id', 'frf.image'),
            $query->expr()->eq('fri.model', $query->createNamedParameter($this->model())),
        ));

        // WHERE these photos are memories indexed
        $query->innerJoin('fri', 'memories', 'm', $query->expr()->eq('m.fileid', 'fri.file'));

        // WHERE the face belongs to a cluster
        $query->innerJoin('frf', 'facerecog_clusters', 'frc', $query->expr()->eq('frc.id', 'frf.cluster'));

        if ($isClusterId) {
            // WHERE faces are in this unnamed cluster
            $query->selectAlias('frc.id', 'cluster_id');
            $query->where($query->expr()->eq('frc.id', $query->createNamedParameter($name, \PDO::PARAM_INT)));
        } else {
            // WHERE faces belong to a cluster of this named person
            $query->innerJoin('frc', 'facerecog_persons', 'frp', $query->expr()->eq('frp.id', 'frc.person'));
            $query->selectAlias('frp.id', 'cluster_id');
            $query->where($query->expr()->eq('frp.name', $query->createNamedParameter($name)));
        }

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->filterFilecache($query);

        // LIMIT results
        if (-6 === $limit) {
            // The cover is keyed by whatever getClusterIdFrom() reports: the
            // cluster id for unnamed clusters, the person id for named ones.
            Covers::filterCover(
                $query,
                self::clusterType(),
                'frf',
                'id',
                $isClusterId ? 'frf.cluster' : 'frc.person',
            );
        } elseif (null !== $limit) {
            $query->setMaxResults($limit);
        }

        // Filter by fileid if specified
        if (null !== $fileid) {
            $query->andWhere($query->expr()->eq('fri.file', $query->createNamedParameter($fileid, \PDO::PARAM_INT)));
        }

        // Sort by date taken so we get recent photos
        $query->addOrderBy('m.datetaken', 'DESC');
        $query->addOrderBy('m.fileid', 'DESC'); // tie-breaker

        // FETCH face detections
        return $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];
    }

    #[\Override]
    public function sortPhotosForPreview(array &$photos): void
    {
        // Convert to recognize format (percentage position-size)
        foreach ($photos as &$p) {
            $p['x'] = (float) $p['x'] / (float) $p['image_width'];
            $p['y'] = (float) $p['y'] / (float) $p['image_height'];
            $p['width'] = (float) $p['width'] / (float) $p['image_width'];
            $p['height'] = (float) $p['height'] / (float) $p['image_height'];
        }

        $this->sortByScores($photos);
    }

    #[\Override]
    public function getPreviewBlob(ISimpleFile $file, array $photo): array
    {
        return $this->cropFace($file, $photo, 1.8);
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

    private function model(): int
    {
        return (int) $this->appConfig->getValueString('facerecognition', 'model', (string) -1);
    }

    private function minFaceInClusters(): int
    {
        return (int) $this->appConfig->getValueString('facerecognition', 'min_faces_in_cluster', (string) 5);
    }

    /**
     * Unnamed clusters: rows of facerecog_clusters with no person yet.
     */
    private function getFaceRecognitionClusters(int $fileid = 0): array
    {
        $query = $this->tq->getBuilder();

        // SELECT all face clusters
        $count = $query->func()->count(SQL::distinct($query, 'm.fileid'));
        $query->select('frc.id')->from('facerecog_clusters', 'frc');
        $query->selectAlias($count, 'count');
        $query->selectAlias('frc.user', 'user_id');

        // WHERE there are faces with this cluster
        $query->innerJoin('frc', 'facerecog_faces', 'frf', $query->expr()->eq('frc.id', 'frf.cluster'));

        // WHERE faces are from images.
        $query->innerJoin('frf', 'facerecog_images', 'fri', $query->expr()->eq('fri.id', 'frf.image'));

        // WHERE these items are memories indexed photos
        $query->innerJoin('fri', 'memories', 'm', $query->expr()->andX(
            $query->expr()->eq('fri.file', 'm.fileid'),
            $query->expr()->eq('fri.model', $query->createNamedParameter($this->model())),
        ));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->filterFilecache($query);

        // GROUP by ID of face cluster
        $query->addGroupBy('frc.id', 'frc.user');

        // WHERE the cluster has not been assigned to a person yet
        $query->andWhere($query->expr()->isNull('frc.person'));

        // The query change if we want the people in an fileid, or the unnamed clusters
        if ($fileid > 0) {
            // WHERE these clusters contain fileid if specified
            $query->andWhere($query->expr()->eq('fri.file', $query->createNamedParameter($fileid)));
        } else {
            // WHERE these clusters has a minimum number of faces
            $query->having($query->expr()->gte($count, SQL::literal($query, $this->minFaceInClusters(), \PDO::PARAM_INT)));
            // WHERE these clusters were not hidden due inconsistencies
            $query->andWhere($query->expr()->eq('frc.is_visible', $query->expr()->literal(1)));
        }

        // ORDER by number of faces in cluster and id for response stability.
        $query->addOrderBy('count', 'DESC');
        $query->addOrderBy('frc.id', 'DESC');

        // It is not worth displaying all unnamed clusters. We show 15 to name them progressively,
        $query->setMaxResults(15);

        // SELECT covers
        $query = SQL::materialize($query, 'frc');
        Covers::selectCover(
            query: $query,
            type: self::clusterType(),
            clusterTable: 'frc',
            clusterTableId: 'id',
            objectTable: 'facerecog_faces',
            objectTableObjectId: 'id',
            objectTableClusterId: 'cluster',
        );

        // SELECT etag for the cover
        $query = SQL::materialize($query, 'frc');
        $this->tq->selectEtag($query, 'cover', 'cover_etag');

        // FETCH all faces
        return $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];
    }

    /**
     * Named people, reached through the clusters that point at them.
     */
    private function getFaceRecognitionPersons(int $fileid = 0): array
    {
        $query = $this->tq->getBuilder();

        // SELECT all face clusters
        $query->select('frp.name')
            ->selectAlias($query->func()->count(SQL::distinct($query, 'm.fileid')), 'count')
            ->selectAlias($query->func()->min('frp.id'), 'id')
            ->selectAlias('frp.user', 'user_id')
            ->from('facerecog_persons', 'frp')
        ;

        // WHERE there are clusters for this person
        $query->innerJoin('frp', 'facerecog_clusters', 'frc', $query->expr()->eq('frp.id', 'frc.person'));

        // WHERE there are faces in those clusters
        $query->innerJoin('frc', 'facerecog_faces', 'frf', $query->expr()->eq('frc.id', 'frf.cluster'));

        // WHERE faces are from images.
        $query->innerJoin('frf', 'facerecog_images', 'fri', $query->expr()->eq('fri.id', 'frf.image'));

        // WHERE these items are memories indexed photos
        $query->innerJoin('fri', 'memories', 'm', $query->expr()->andX(
            $query->expr()->eq('fri.file', 'm.fileid'),
            $query->expr()->eq('fri.model', $query->createNamedParameter($this->model())),
        ));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->filterFilecache($query);

        // GROUP by name of face clusters
        $query->andWhere($query->expr()->isNotNull('frp.name'));

        // WHERE these clusters contain fileid if specified
        if ($fileid > 0) {
            $query->andWhere($query->expr()->eq('fri.file', $query->createNamedParameter($fileid)));
        }

        $query->addGroupBy('frp.name', 'frp.user');

        // ORDER by number of faces in cluster
        $query->addOrderBy('count', 'DESC');
        $query->addOrderBy('frp.name', 'ASC');

        // SELECT to get all covers
        $query = SQL::materialize($query, 'frp');
        Covers::selectCover(
            query: $query,
            type: self::clusterType(),
            clusterTable: 'frp',
            clusterTableId: 'id',
            objectTable: 'facerecog_faces',
            objectTableObjectId: 'id',
            // A face points at a cluster and the cluster at the person, so the
            // cover validation needs the extra hop joined in below.
            objectTableClusterId: 'cov_frc.person',
            objectTableJoin: static function (IQueryBuilder $sq): void {
                $sq->innerJoin('cov_objs', 'facerecog_clusters', 'cov_frc', $sq->expr()->eq('cov_frc.id', 'cov_objs.cluster'));
            },
        );

        // SELECT etag for the cover
        $query = SQL::materialize($query, 'frp');
        $this->tq->selectEtag($query, 'frp.cover', 'cover_etag');

        // FETCH all faces
        return $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];
    }
}
