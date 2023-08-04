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
use OCP\IRequest;

class RecognizeBackend extends Backend
{
    use PeopleBackendUtils;

    protected TimelineQuery $tq;
    protected IRequest $request;

    public function __construct(TimelineQuery $tq, IRequest $request)
    {
        $this->tq = $tq;
        $this->request = $request;
    }

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

    public function transformDayQuery(&$query, bool $aggregate): void
    {
        // Check if Recognize is enabled
        if (!$this->isEnabled()) {
            throw \OCA\Memories\Exceptions::NotEnabled('Recognize');
        }

        // Get name and uid of face user
        $faceStr = (string) $this->request->getParam('recognize');
        $faceNames = explode('/', $faceStr);
        if (2 !== \count($faceNames)) {
            throw new \Exception('Invalid face query');
        }

        // Starting with Recognize v3.6, the detections are duplicated for each user
        // So we don't need to use the user ID provided by the user, but retain
        // this here for backwards compatibility + API consistency with Face Recognition
        // $faceUid = $faceNames[0];

        $faceName = $faceNames[1];

        if (!$aggregate) {
            // Multiple detections for the same image
            $query->addSelect('rfd.id AS faceid');

            // Face Rect
            if ($this->request->getParam('facerect')) {
                $query->addSelect(
                    'rfd.width AS face_w',
                    'rfd.height AS face_h',
                    'rfd.x AS face_x',
                    'rfd.y AS face_y',
                );
            }
        }

        // Join with cluster
        $clusterQuery = null;
        if ('NULL' === $faceName) {
            $clusterQuery = $query->expr()->eq('rfd.cluster_id', $query->expr()->literal(-1));
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
        if (!isset($row) || !isset($row['face_w'])) {
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

    public function getClusters(int $fileid = 0): array
    {
        $query = $this->tq->getBuilder();

        // SELECT all face clusters
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'), 'count');
        $query->select('rfc.id', 'rfc.user_id', 'rfc.title', $count)->from('recognize_face_clusters', 'rfc');

        // WHERE there are faces with this cluster
        $query->innerJoin('rfc', 'recognize_face_detections', 'rfd', $query->expr()->eq('rfc.id', 'rfd.cluster_id'));

        // WHERE these items are memories indexed photos
        $query->innerJoin('rfd', 'memories', 'm', $query->expr()->eq('m.fileid', 'rfd.file_id'));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->tq->joinFilecache($query);

        // WHERE this cluster belongs to the user
        $query->where($query->expr()->eq('rfc.user_id', $query->createNamedParameter(Util::getUID())));

        // WHERE these clusters contain fileid if specified
        if ($fileid > 0) {
            $fSq = $this->tq->getBuilder()
                ->select('rfd.file_id')
                ->from('recognize_face_detections', 'rfd')
                ->where($query->expr()->andX(
                    $query->expr()->eq('rfd.cluster_id', 'rfc.id'),
                    $query->expr()->eq('rfd.file_id', $query->createNamedParameter($fileid, \PDO::PARAM_INT)),
                ))
                ->getSQL()
            ;
            $query->andWhere($query->createFunction("EXISTS ({$fSq})"));
        }

        // GROUP by ID of face cluster
        $query->groupBy('rfc.id');

        // ORDER by number of faces in cluster
        $query->orderBy($query->createFunction("rfc.title <> ''"), 'DESC');
        $query->addOrderBy('count', 'DESC');
        $query->addOrderBy('rfc.id'); // tie-breaker

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

    public static function getClusterId(array $cluster)
    {
        return $cluster['id'];
    }

    public function getPhotos(string $name, ?int $limit = null): array
    {
        $query = $this->tq->getBuilder();

        // SELECT face detections for ID
        $query->select(
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
        $this->sortByScores($photos);
    }

    public function getPreviewBlob($file, $photo): array
    {
        return $this->cropFace($file, $photo, 1.5);
    }

    public function getPreviewQuality(): int
    {
        return 2048;
    }
}
