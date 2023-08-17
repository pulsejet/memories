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

use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Util;
use OCP\IConfig;
use OCP\IRequest;

class FaceRecognitionBackend extends Backend
{
    use PeopleBackendUtils;

    protected IRequest $request;
    protected TimelineQuery $tq;
    protected IConfig $config;

    public function __construct(
        IRequest $request,
        TimelineQuery $tq,
        IConfig $config
    ) {
        $this->request = $request;
        $this->tq = $tq;
        $this->config = $config;
    }

    public static function appName(): string
    {
        return 'Face Recognition';
    }

    public static function clusterType(): string
    {
        return 'facerecognition';
    }

    public function isEnabled(): bool
    {
        return Util::facerecognitionIsInstalled()
               && Util::facerecognitionIsEnabled();
    }

    public function transformDayQuery(&$query, bool $aggregate): void
    {
        $personStr = (string) $this->request->getParam('facerecognition');

        // Get title and uid of face user
        $personNames = explode('/', $personStr);
        if (2 !== \count($personNames)) {
            throw new \Exception('Invalid person query');
        }

        $personUid = $personNames[0];
        $personName = $personNames[1];

        // Join with images
        $query->innerJoin('m', 'facerecog_images', 'fri', $query->expr()->andX(
            $query->expr()->eq('fri.file', 'm.fileid'),
            $query->expr()->eq('fri.model', $query->createNamedParameter($this->model())),
        ));

        // Join with faces
        $query->innerJoin('fri', 'facerecog_faces', 'frf', $query->expr()->eq('frf.image', 'fri.id'));

        // Join with persons
        $nameField = is_numeric($personName) ? 'frp.id' : 'frp.name';
        $query->innerJoin('frf', 'facerecog_persons', 'frp', $query->expr()->andX(
            $query->expr()->eq('frf.person', 'frp.id'),
            $query->expr()->eq('frp.user', $query->createNamedParameter($personUid)),
            $query->expr()->eq($nameField, $query->createNamedParameter($personName)),
        ));

        // Add face rect
        if (!$aggregate && $this->request->getParam('facerect')) {
            $query->selectAlias('frf.x', 'face_x')
                ->selectAlias('frf.y', 'face_y')
                ->selectAlias('frf.width', 'face_width')
                ->selectAlias('frf.height', 'face_height')
                ->selectAlias('m.w', 'image_width')
                ->selectAlias('m.h', 'image_height')
            ;
        }
    }

    public function transformDayPost(array &$row): void
    {
        // Differentiate Recognize queries from Face Recognition
        if (!isset($row) || !isset($row['face_width']) || !isset($row['image_width'])) {
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

    public function getClustersInternal(int $fileid = 0): array
    {
        $faces = array_merge(
            $this->getFaceRecognitionPersons($fileid),
            $this->getFaceRecognitionClusters($fileid)
        );

        // Post process
        foreach ($faces as &$row) {
            $row['id'] = \array_key_exists('name', $row) ? $row['name'] : (int) $row['id'];
            $row['count'] = (int) $row['count'];
        }

        return $faces;
    }

    public static function getClusterId(array $cluster)
    {
        return $cluster['id'];
    }

    public function getPhotos(string $name, ?int $limit = null): array
    {
        $query = $this->tq->getBuilder();

        // SELECT face detections
        $query->select(
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

        $query->innerJoin('frf', 'facerecog_persons', 'frp', $query->expr()->eq('frp.id', 'frf.person'));

        // WHERE faces are from id persons (or a cluster).
        $nameField = is_numeric($name) ? 'frp.id' : 'frp.name';
        $query->where($query->expr()->eq($nameField, $query->createNamedParameter($name)));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->joinFilecache($query);

        // LIMIT results
        if (null !== $limit) {
            $query->setMaxResults($limit);
        }

        // Sort by date taken so we get recent photos
        $query->orderBy('m.datetaken', 'DESC');
        $query->addOrderBy('m.fileid', 'DESC'); // tie-breaker

        // FETCH face detections
        return $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];
    }

    public function sortPhotosForPreview(array &$photos)
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

    public function getPreviewBlob($file, $photo): array
    {
        return $this->cropFace($file, $photo, 1.8);
    }

    public function getPreviewQuality(): int
    {
        return 2048;
    }

    private function model(): int
    {
        return (int) $this->config->getAppValue('facerecognition', 'model', -1);
    }

    private function minFaceInClusters(): int
    {
        return (int) $this->config->getAppValue('facerecognition', 'min_faces_in_cluster', 5);
    }

    private function getFaceRecognitionClusters(int $fileid = 0)
    {
        $query = $this->tq->getBuilder();

        // SELECT all face clusters
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'));
        $query->select('frp.id')->from('facerecog_persons', 'frp');
        $query->selectAlias($count, 'count');
        $query->selectAlias('frp.user', 'user_id');

        // WHERE there are faces with this cluster
        $query->innerJoin('frp', 'facerecog_faces', 'frf', $query->expr()->eq('frp.id', 'frf.person'));

        // WHERE faces are from images.
        $query->innerJoin('frf', 'facerecog_images', 'fri', $query->expr()->eq('fri.id', 'frf.image'));

        // WHERE these items are memories indexed photos
        $query->innerJoin('fri', 'memories', 'm', $query->expr()->andX(
            $query->expr()->eq('fri.file', 'm.fileid'),
            $query->expr()->eq('fri.model', $query->createNamedParameter($this->model())),
        ));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->joinFilecache($query);

        // GROUP by ID of face cluster
        $query->groupBy('frp.id');
        $query->addGroupBy('frp.user');
        $query->where($query->expr()->isNull('frp.name'));

        // The query change if we want the people in an fileid, or the unnamed clusters
        if ($fileid > 0) {
            // WHERE these clusters contain fileid if specified
            $query->andWhere($query->expr()->eq('fri.file', $query->createNamedParameter($fileid)));
        } else {
            // WHERE these clusters has a minimum number of faces
            $query->having($query->expr()->gte($count, $query->expr()->literal($this->minFaceInClusters(), \PDO::PARAM_INT)));
            // WHERE these clusters were not hidden due inconsistencies
            $query->andWhere($query->expr()->eq('frp.is_visible', $query->expr()->literal(1)));
        }

        // ORDER by number of faces in cluster and id for response stability.
        $query->orderBy('count', 'DESC');
        $query->addOrderBy('frp.id', 'DESC');

        // It is not worth displaying all unnamed clusters. We show 15 to name them progressively,
        $query->setMaxResults(15);

        // FETCH all faces
        return $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];
    }

    private function getFaceRecognitionPersons(int $fileid = 0)
    {
        $query = $this->tq->getBuilder();

        // SELECT all face clusters
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'));
        $query->select('frp.name')->from('facerecog_persons', 'frp');
        $query->selectAlias($count, 'count');
        $query->selectAlias('frp.user', 'user_id');

        // WHERE there are faces with this cluster
        $query->innerJoin('frp', 'facerecog_faces', 'frf', $query->expr()->eq('frp.id', 'frf.person'));

        // WHERE faces are from images.
        $query->innerJoin('frf', 'facerecog_images', 'fri', $query->expr()->eq('fri.id', 'frf.image'));

        // WHERE these items are memories indexed photos
        $query->innerJoin('fri', 'memories', 'm', $query->expr()->andX(
            $query->expr()->eq('fri.file', 'm.fileid'),
            $query->expr()->eq('fri.model', $query->createNamedParameter($this->model())),
        ));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->joinFilecache($query);

        // GROUP by name of face clusters
        $query->where($query->expr()->isNotNull('frp.name'));

        // WHERE these clusters contain fileid if specified
        if ($fileid > 0) {
            $query->andWhere($query->expr()->eq('fri.file', $query->createNamedParameter($fileid)));
        }

        $query->groupBy('frp.user');
        $query->addGroupBy('frp.name');

        // ORDER by number of faces in cluster
        $query->orderBy('count', 'DESC');
        $query->addOrderBy('frp.name', 'ASC');

        // FETCH all faces
        return $this->tq->executeQueryWithCTEs($query)->fetchAll() ?: [];
    }
}
