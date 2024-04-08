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
use OCP\IRequest;

class RecognizeBackend extends Backend
{
    use PeopleBackendUtils;

    public function __construct(
        protected TimelineQuery $tq,
        protected IRequest $request,
    ) {}

    public static function appName(): string
    {
        return 'Recognize';
    }

    public static function clusterType(): string
    {
        return 'recognize';
    }

    public function isEnabled(): bool
    {
        return Util::recognizeIsEnabled();
    }

    public function transformDayQuery(IQueryBuilder &$query, bool $aggregate): void
    {
        // Check if Recognize is enabled
        if (!$this->isEnabled()) {
            throw \OCA\Memories\Exceptions::NotEnabled('Recognize');
        }

        // Note: all of this is duplicated in nameToClusterId since we want to avoid
        // making two queries for the getting the cluster_id and the actual clusters
        $faceStr = (string) $this->request->getParam('recognize');
        $faceNames = explode('/', $faceStr);
        if (2 !== \count($faceNames)) {
            throw new \Exception('Invalid face query');
        }

        // Starting with Recognize v3.6, the detections are duplicated for each user
        // So we don't need to use the user ID provided by the user, but retain
        // this here for backwards compatibility + API consistency with Face Recognition
        [$faceUid, $faceName] = $faceNames;

        if (!$aggregate) {
            // Multiple detections for the same image
            $query->selectAlias('rfd.id', 'faceid');

            // Face Rect
            if ($this->request->getParam('facerect')) {
                $query->selectAlias('rfd.width', 'face_w')
                    ->selectAlias('rfd.height', 'face_h')
                    ->selectAlias('rfd.x', 'face_x')
                    ->selectAlias('rfd.y', 'face_y')
                ;
            }
        }

        // Join with cluster
        $clusterQuery = null;
        if ('NULL' === $faceName) {
            $clusterQuery = $query->expr()->andX(
                $query->expr()->eq('rfd.user_id', $query->createNamedParameter(Util::getUID())),
                $query->expr()->eq('rfd.cluster_id', $query->expr()->literal(-1)),
            );
        } else {
            $nameField = is_numeric($faceName) ? 'rfc.id' : 'rfc.title';
            $query->innerJoin('m', 'recognize_face_clusters', 'rfc', $query->expr()->andX(
                $query->expr()->eq('rfc.user_id', $query->createNamedParameter(Util::getUID())),
                $query->expr()->eq($nameField, $query->createNamedParameter($faceName)),
            ));
            $clusterQuery = $query->expr()->eq('rfd.cluster_id', 'rfc.id');
        }

        // Join with detections
        $query->innerJoin('m', 'recognize_face_detections', 'rfd', $query->expr()->andX(
            $query->expr()->eq('rfd.file_id', 'm.fileid'),
            $clusterQuery,
        ));
    }

    public function transformDayPost(array &$row): void
    {
        // Differentiate Recognize queries from Face Recognition
        if (!isset($row['face_w'])) {
            return;
        }

        // Convert face rect to object
        $row['facerect'] = [
            'w' => (float) $row['face_w'],
            'h' => (float) $row['face_h'],
            'x' => (float) $row['face_x'],
            'y' => (float) $row['face_y'],
        ];

        unset($row['face_w'], $row['face_h'], $row['face_x'], $row['face_y']);
    }

    public function getClustersInternal(int $fileid = 0): array
    {
        $query = $this->tq->getBuilder();

        // SELECT all face clusters
        $count = $query->func()->count(SQL::distinct($query, 'm.fileid'), 'count');
        $query->select('rfc.id', 'rfc.user_id', 'rfc.title', $count)->from('recognize_face_clusters', 'rfc');

        // WHERE there are faces with this cluster
        $query->innerJoin('rfc', 'recognize_face_detections', 'rfd', $query->expr()->eq('rfc.id', 'rfd.cluster_id'));

        // WHERE these items are memories indexed photos
        $query->innerJoin('rfd', 'memories', 'm', $query->expr()->eq('m.fileid', 'rfd.file_id'));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->filterFilecache($query);

        // WHERE this cluster belongs to the user
        $query->andWhere($query->expr()->eq('rfc.user_id', $query->createNamedParameter(Util::getUID())));

        // WHERE these clusters contain fileid if specified
        if ($fileid > 0) {
            // This screws up the count but we don't use it right now
            // and scanning the parent of each file is too expensive
            $query->andWhere($query->expr()->eq('rfd.file_id', $query->createNamedParameter($fileid, \PDO::PARAM_INT)));
        }

        // GROUP by ID of face cluster
        $query->addGroupBy('rfc.id');

        // ORDER by number of faces in cluster
        $query->addOrderBy($query->createFunction("rfc.title <> ''"), 'DESC');
        $query->addOrderBy('count', 'DESC');
        $query->addOrderBy('rfc.id'); // tie-breaker

        // SELECT to get all covers
        $query = SQL::materialize($query, 'rfc');
        Covers::selectCover(
            query: $query,
            type: self::clusterType(),
            clusterTable: 'rfc',
            clusterTableId: 'id',
            objectTable: 'recognize_face_detections',
            objectTableObjectId: 'id',
            objectTableClusterId: 'cluster_id',
        );

        // SELECT etag for the cover
        // Since the "cover" is the face detection, we need the actual file for etag
        $query = SQL::materialize($query, 'rfc');
        $cfSq = $this->tq->getBuilder();
        $cfSq->select('file_id')
            ->from('recognize_face_detections', 'rfd')
            ->where($cfSq->expr()->eq('rfd.id', 'rfc.cover'))
            ->setMaxResults(1)
        ;
        $this->tq->selectEtag($query, SQL::subquery($query, $cfSq), 'cover_etag');

        // FETCH all faces
        $faces = $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];

        // Post process
        foreach ($faces as &$row) {
            $row['id'] = (int) $row['id'];
            $row['count'] = (int) $row['count'];
            $row['name'] = $row['title'];
            unset($row['title']);
        }

        return $faces;
    }

    public static function getClusterId(array $cluster): int|string
    {
        return $cluster['id'];
    }

    public function getPhotos(string $name, ?int $limit = null, ?int $fileid = null): array
    {
        $name = $this->nameToClusterId($name);
        if (!$name) {
            return [];
        }

        $query = $this->tq->getBuilder();

        // SELECT face detections for ID
        $query->select(
            'rfd.id AS faceid',
            'rfd.cluster_id',
            'rfd.file_id',              // Get actual file
            'rfd.x',                    // Image cropping
            'rfd.y',
            'rfd.width',
            'rfd.height',
            'm.w as image_width',       // Scoring
            'm.h as image_height',
            'm.fileid',
            'm.datetaken',              // Just in case, for postgres
        )->from('recognize_face_detections', 'rfd');

        // WHERE detection belongs to this cluster
        $query->where($query->expr()->eq('rfd.cluster_id', $query->createNamedParameter($name)));

        // WHERE these photos are memories indexed
        $query->innerJoin('rfd', 'memories', 'm', $query->expr()->eq('m.fileid', 'rfd.file_id'));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->filterFilecache($query);

        // LIMIT results
        if (-6 === $limit) {
            Covers::filterCover($query, self::clusterType(), 'rfd', 'id', 'cluster_id');
        } elseif (null !== $limit) {
            $query->setMaxResults($limit);
        }

        // Filter by fileid if specified
        if (null !== $fileid) {
            $query->andWhere($query->expr()->eq('rfd.file_id', $query->createNamedParameter($fileid, \PDO::PARAM_INT)));
        }

        // Sort by date taken so we get recent photos
        $query->addOrderBy('m.datetaken', 'DESC');
        $query->addOrderBy('m.fileid', 'DESC'); // tie-breaker

        // FETCH face detections
        return $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];
    }

    public function sortPhotosForPreview(array &$photos): void
    {
        $this->sortByScores($photos);
    }

    public function getPreviewBlob(ISimpleFile $file, array $photo): array
    {
        return $this->cropFace($file, $photo, 1.5);
    }

    public function getPreviewQuality(): int
    {
        return 2048;
    }

    public function getCoverObjId(array $photo): int
    {
        return (int) $photo['faceid'];
    }

    public function getClusterIdFrom(array $photo): int
    {
        return (int) $photo['cluster_id'];
    }

    /**
     * Get the numeric cluster ID for a non-numeric string
     * This runs the actual query to find the cluster
     * See the definition of transformDayQuery for more details.
     */
    private function nameToClusterId(string $name): false|int
    {
        if (!is_numeric($name)) {
            $faceNames = explode('/', $name);
            if (2 !== \count($faceNames)) {
                return false;
            }

            [$faceUid, $faceName] = $faceNames;

            // Get cluster ID
            $nameField = is_numeric($faceName) ? 'rfc.id' : 'rfc.title';
            $query = $this->tq->getBuilder();
            $query->select('id')
                ->from('recognize_face_clusters', 'rfc')
                ->where($query->expr()->eq($nameField, $query->createNamedParameter($faceName)))
            ;

            if ($id = $query->executeQuery()->fetchOne()) {
                return (int) $id;
            }

            return false;
        }

        return (int) $name;
    }
}
