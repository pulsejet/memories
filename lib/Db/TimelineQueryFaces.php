<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

trait TimelineQueryFaces
{
    protected IDBConnection $connection;

    public function transformFaceFilter(IQueryBuilder &$query, string $userId, string $faceStr)
    {
        // Get title and uid of face user
        $faceNames = explode('/', $faceStr);
        if (2 !== \count($faceNames)) {
            throw new \Exception('Invalid face query');
        }
        $faceUid = $faceNames[0];
        $faceName = $faceNames[1];

        // Join with cluster
        $nameField = is_numeric($faceName) ? 'rfc.id' : 'rfc.title';
        $query->innerJoin('m', 'recognize_face_clusters', 'rfc', $query->expr()->andX(
            $query->expr()->eq('rfc.user_id', $query->createNamedParameter($faceUid)),
            $query->expr()->eq($nameField, $query->createNamedParameter($faceName)),
        ));

        // Join with detections
        $query->innerJoin('m', 'recognize_face_detections', 'rfd', $query->expr()->andX(
            $query->expr()->eq('rfd.file_id', 'm.fileid'),
            $query->expr()->eq('rfd.cluster_id', 'rfc.id'),
        ));
    }

    public function transformFaceRect(IQueryBuilder &$query, string $userId)
    {
        // Include detection params in response
        $query->addSelect(
            'rfd.width AS face_w',
            'rfd.height AS face_h',
            'rfd.x AS face_x',
            'rfd.y AS face_y',
        );
    }

    public function getFaces(TimelineRoot &$root)
    {
        $query = $this->connection->getQueryBuilder();

        // SELECT all face clusters
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'), 'count');
        $query->select('rfc.id', 'rfc.user_id', 'rfc.title', $count)->from('recognize_face_clusters', 'rfc');

        // WHERE there are faces with this cluster
        $query->innerJoin('rfc', 'recognize_face_detections', 'rfd', $query->expr()->eq('rfc.id', 'rfd.cluster_id'));

        // WHERE these items are memories indexed photos
        $query->innerJoin('rfd', 'memories', 'm', $query->expr()->eq('m.fileid', 'rfd.file_id'));

        // WHERE these photos are in the user's requested folder recursively
        $query = $this->joinFilecache($query, $root, true, false);

        // GROUP by ID of face cluster
        $query->groupBy('rfc.id');

        // ORDER by number of faces in cluster
        $query->orderBy($query->createFunction("rfc.title <> ''"), 'DESC');
        $query->addOrderBy('count', 'DESC');
        $query->addOrderBy('rfc.id'); // tie-breaker

        // FETCH all faces
        $cursor = $this->executeQueryWithCTEs($query);
        $faces = $cursor->fetchAll();

        // Post process
        foreach ($faces as &$row) {
            $row['id'] = (int) $row['id'];
            $row['name'] = $row['title'];
            unset($row['title']);
            $row['count'] = (int) $row['count'];
        }

        return $faces;
    }

    public function getFacePreviewDetection(TimelineRoot &$root, int $id)
    {
        $query = $this->connection->getQueryBuilder();

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
        $query->where($query->expr()->eq('rfd.cluster_id', $query->createNamedParameter($id)));

        // WHERE these photos are memories indexed
        $query->innerJoin('rfd', 'memories', 'm', $query->expr()->eq('m.fileid', 'rfd.file_id'));

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
            $p['score'] = $positionScore * $imgSizeScore * $faceSizeScore;
        }

        // Sort previews by score descending
        usort($previews, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return $previews;
    }

    /** Convert face fields to object */
    private function processFace(&$row, $days = false)
    {
        if (!isset($row) || !isset($row['face_w'])) {
            return;
        }

        if (!$days) {
            $row['facerect'] = [
                'w' => (float) $row['face_w'],
                'h' => (float) $row['face_h'],
                'x' => (float) $row['face_x'],
                'y' => (float) $row['face_y'],
            ];
        }

        unset($row['face_w'], $row['face_h'], $row['face_x'], $row['face_y']);
    }
}
