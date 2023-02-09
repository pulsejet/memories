<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\IDBConnection;

trait TimelineQueryPeopleFaceRecognition
{
    protected IDBConnection $connection;

    public function transformPeopleFaceRecognitionFilter(IQueryBuilder &$query, string $userId, int $currentModel, string $personStr)
    {
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
            $query->expr()->eq('fri.model', $query->createNamedParameter($currentModel)),
        ));

        // Join with faces
        $query->innerJoin(
            'fri',
            'facerecog_faces',
            'frf',
            $query->expr()->eq('frf.image', 'fri.id')
        );

        // Join with persons
        $nameField = is_numeric($personName) ? 'frp.id' : 'frp.name';
        $query->innerJoin('frf', 'facerecog_persons', 'frp', $query->expr()->andX(
            $query->expr()->eq('frf.person', 'frp.id'),
            $query->expr()->eq('frp.user', $query->createNamedParameter($personUid)),
            $query->expr()->eq($nameField, $query->createNamedParameter($personName)),
        ));
    }

    public function transformPeopleFaceRecognitionRect(IQueryBuilder &$query, string $userId)
    {
        // Include detection params in response
        $query->addSelect(
            'frf.x AS face_x',
            'frf.y AS face_y',
            'frf.width AS face_width',
            'frf.height AS face_height',
            'm.w AS image_width',
            'm.h AS image_height',
        );
    }

    public function getPeopleFaceRecognition(TimelineRoot &$root, int $currentModel, bool $show_clusters = false, bool $show_singles = false, bool $show_hidden = false)
    {
        if ($show_clusters) {
            return $this->getFaceRecognitionClusters($root, $currentModel, $show_singles, $show_hidden);
        }

        return $this->getFaceRecognitionPersons($root, $currentModel);
    }

    public function getFaceRecognitionPreview(TimelineRoot &$root, $currentModel, $previewId)
    {
        $query = $this->connection->getQueryBuilder();

        // SELECT face detections
        $query->select(
            'fri.file as file_id',      // Get actual file
            'frf.x',                    // Image cropping
            'frf.y',
            'frf.width',
            'frf.height',
            'm.w as image_width',       // Scoring
            'm.h as image_height',
            'frf.confidence',
            'm.fileid',
            'm.datetaken',              // Just in case, for postgres
        )->from('facerecog_faces', 'frf');

        // WHERE faces are from images and current model.
        $query->innerJoin('frf', 'facerecog_images', 'fri', $query->expr()->andX(
            $query->expr()->eq('fri.id', 'frf.image'),
            $query->expr()->eq('fri.model', $query->createNamedParameter($currentModel)),
        ));

        // WHERE these photos are memories indexed
        $query->innerJoin('fri', 'memories', 'm', $query->expr()->eq('m.fileid', 'fri.file'));

        $query->innerJoin('frf', 'facerecog_persons', 'frp', $query->expr()->eq('frp.id', 'frf.person'));
        if (is_numeric($previewId)) {
            // WHERE faces are from id persons (a cluster).
            $query->where($query->expr()->eq('frp.id', $query->createNamedParameter($previewId)));
        } else {
            // WHERE faces are from name on persons.
            $query->where($query->expr()->eq('frp.name', $query->createNamedParameter($previewId)));
        }

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->joinFilecache($query, $root, true, false);

        // LIMIT results
        $query->setMaxResults(15);

        // Sort by date taken so we get recent photos
        $query->orderBy('m.datetaken', 'DESC');
        $query->addOrderBy('m.fileid', 'DESC'); // tie-breaker

        // FETCH face detections
        $cursor = $this->executeQueryWithCTEs($query);
        $previews = $cursor->fetchAll();
        if (empty($previews)) {
            return null;
        }

        // Score the face detections
        foreach ($previews as &$p) {
            // Get actual pixel size of face
            $iw = min((int) ($p['image_width'] ?: 512), 2048);
            $ih = min((int) ($p['image_height'] ?: 512), 2048);

            // Get percentage position and size
            $p['x'] = (float) $p['x'] / $p['image_width'];
            $p['y'] = (float) $p['y'] / $p['image_height'];
            $p['width'] = (float) $p['width'] / $p['image_width'];
            $p['height'] = (float) $p['height'] / $p['image_height'];

            $w = (float) $p['width'];
            $h = (float) $p['height'];

            // Get center of face
            $x = (float) $p['x'] + (float) $p['width'] / 2;
            $y = (float) $p['y'] + (float) $p['height'] / 2;

            // 3D normal distribution - if the face is closer to the center, it's better
            $positionScore = exp(-($x - 0.5) ** 2 * 4) * exp(-($y - 0.5) ** 2 * 4);

            // Root size distribution - if the image is bigger, it's better,
            // but it doesn't matter beyond a certain point
            $imgSizeScore = ($iw * 100) ** (1 / 2) * ($ih * 100) ** (1 / 2);

            // Faces occupying too much of the image don't look particularly good
            $faceSizeScore = (-$w ** 2 + $w) * (-$h ** 2 + $h);

            // Combine scores
            $p['score'] = $positionScore * $imgSizeScore * $faceSizeScore * $p['confidence'];
        }

        // Sort previews by score descending
        usort($previews, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return $previews;
    }

    private function getFaceRecognitionClusters(TimelineRoot &$root, int $currentModel, bool $show_singles = false, bool $show_hidden = false)
    {
        $query = $this->connection->getQueryBuilder();

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
            $query->expr()->eq('fri.model', $query->createNamedParameter($currentModel)),
        ));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->joinFilecache($query, $root, true, false);

        // GROUP by ID of face cluster
        $query->groupBy('frp.id');
        $query->addGroupBy('frp.user');
        $query->where($query->expr()->isNull('frp.name'));

        // By default hides individual faces when they have no name.
        if (!$show_singles) {
            $query->having($count, $query->createNamedParameter(1));
        }

        // By default it shows the people who were not hidden
        if (!$show_hidden) {
            $query->andWhere($query->expr()->eq('frp.is_visible', $query->createNamedParameter(true)));
        }

        // ORDER by number of faces in cluster and id for response stability.
        $query->orderBy('count', 'DESC');
        $query->addOrderBy('frp.id', 'DESC');

        // It is not worth displaying all unnamed clusters. We show 15 to name them progressively,
        $query->setMaxResults(15);

        // FETCH all faces
        $cursor = $this->executeQueryWithCTEs($query);
        $faces = $cursor->fetchAll();

        // Post process
        foreach ($faces as &$row) {
            $row['id'] = (int) $row['id'];
            $row['count'] = (int) $row['count'];
        }

        return $faces;
    }

    private function getFaceRecognitionPersons(TimelineRoot &$root, int $currentModel)
    {
        $query = $this->connection->getQueryBuilder();

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
            $query->expr()->eq('fri.model', $query->createNamedParameter($currentModel)),
        ));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->joinFilecache($query, $root, true, false);

        // GROUP by name of face clusters
        $query->where($query->expr()->isNotNull('frp.name'));
        $query->groupBy('frp.user');
        $query->addGroupBy('frp.name');

        // ORDER by number of faces in cluster
        $query->orderBy('count', 'DESC');
        $query->addOrderBy('frp.name', 'ASC');

        // FETCH all faces
        $cursor = $this->executeQueryWithCTEs($query);
        $faces = $cursor->fetchAll();

        // Post process
        foreach ($faces as &$row) {
            $row['id'] = $row['name'];
            $row['count'] = (int) $row['count'];
        }

        return $faces;
    }

    /** Convert face fields to object */
    private function processFaceRecognitionDetection(&$row, $days = false)
    {
        if (!isset($row)) {
            return;
        }

        // Differentiate Recognize queries from Face Recognition
        if (!isset($row['face_width']) || !isset($row['image_width'])) {
            return;
        }

        if (!$days) {
            $row['facerect'] = [
                // Get percentage position and size
                'w' => (float) $row['face_width'] / $row['image_width'],
                'h' => (float) $row['face_height'] / $row['image_height'],
                'x' => (float) $row['face_x'] / $row['image_width'],
                'y' => (float) $row['face_y'] / $row['image_height'],
            ];
        }

        unset($row['face_x'], $row['face_y'], $row['face_w'], $row['face_h'], $row['image_height'], $row['image_width']);
    }
}
