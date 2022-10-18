<?php
declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;

trait TimelineQueryFaces {
    protected IDBConnection $connection;

    public function transformFaceFilter(IQueryBuilder &$query, string $userId, string $faceStr) {
        // Get title and uid of face user
        $faceNames = explode('/', $faceStr);
        if (count($faceNames) !== 2) throw new \Exception("Invalid face query");
        $faceUid = $faceNames[0];
        $faceName = $faceNames[1];

        // Join with cluster
        $nameField = is_numeric($faceName) ? 'rfc.id' : 'rfc.title';
        $query->innerJoin('m', 'recognize_face_clusters', 'rfc', $query->expr()->andX(
            $query->expr()->eq('user_id', $query->createNamedParameter($faceUid)),
            $query->expr()->eq($nameField, $query->createNamedParameter($faceName)),
        ));

        // Join with detections
        $query->innerJoin('m', 'recognize_face_detections', 'rfd', $query->expr()->andX(
            $query->expr()->eq('rfd.file_id', 'm.fileid'),
            $query->expr()->eq('rfd.cluster_id', 'rfc.id'),
        ));
    }

    public function transformFaceRect(IQueryBuilder &$query, string $userId) {
        // Include detection params in response
        $query->addSelect(
            'rfd.width AS face_w',
            'rfd.height AS face_h',
            'rfd.x AS face_x',
            'rfd.y AS face_y',
        );
    }

    /** Convert face fields to object */
    private function processFace(&$row, $days=false) {
        if (!isset($row) || !isset($row['face_w'])) return;

        if (!$days) {
            $row["facerect"] = [
                "w" => floatval($row["face_w"]),
                "h" => floatval($row["face_h"]),
                "x" => floatval($row["face_x"]),
                "y" => floatval($row["face_y"]),
            ];
        }

        unset($row["face_w"]);
        unset($row["face_h"]);
        unset($row["face_x"]);
        unset($row["face_y"]);
    }

    public function getFaces(Folder $folder) {
        $query = $this->connection->getQueryBuilder();

        // SELECT all face clusters
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'), 'count');
        $query->select('rfc.id', 'rfc.user_id', 'rfc.title', $count)->from('recognize_face_clusters', 'rfc');

        // WHERE there are faces with this cluster
        $query->innerJoin('rfc', 'recognize_face_detections', 'rfd', $query->expr()->eq('rfc.id', 'rfd.cluster_id'));

        // WHERE these items are memories indexed photos
        $query->innerJoin('rfd', 'memories', 'm', $query->expr()->eq('m.fileid', 'rfd.file_id'));

        // WHERE these photos are in the user's requested folder recursively
        $query->innerJoin('m', 'filecache', 'f', $this->getFilecacheJoinQuery($query, $folder, true, false));

        // GROUP by ID of face cluster
        $query->groupBy('rfc.id');

        // ORDER by number of faces in cluster
        $query->orderBy($query->createFunction("rfc.title <> ''"), 'DESC');
        $query->addOrderBy('count', 'DESC');
        $query->addOrderBy('rfc.id'); // tie-breaker

        // FETCH all faces
        $faces = $query->executeQuery()->fetchAll();

        // Post process
        foreach($faces as &$row) {
            $row['id'] = intval($row['id']);
            $row["name"] = $row["title"];
            unset($row["title"]);
            $row["count"] = intval($row["count"]);
        }

        return $faces;
    }

    public function getFacePreviewDetection(Folder &$folder, int $id) {
        $query = $this->connection->getQueryBuilder();

        // SELECT face detections for ID
        $query->select(
            'rfd.file_id',                                  // Needed to get the actual file
            'rfd.x', 'rfd.y', 'rfd.width', 'rfd.height',    // Image cropping
            'm.w as image_width', 'm.h as image_height',    // Scoring
            'm.fileid', 'm.datetaken',                      // Just in case, for postgres
        )->from('recognize_face_detections', 'rfd');
        $query->where($query->expr()->eq('rfd.cluster_id', $query->createNamedParameter($id)));

        // WHERE these photos are memories indexed
        $query->innerJoin('rfd', 'memories', 'm', $query->expr()->eq('m.fileid', 'rfd.file_id'));

        // WHERE these photos are in the user's requested folder recursively
        $query->innerJoin('m', 'filecache', 'f', $this->getFilecacheJoinQuery($query, $folder, true, false));

        // LIMIT results
        $query->setMaxResults(15);

        // Sort by date taken so we get recent photos
        $query->orderBy('m.datetaken', 'DESC');
        $query->addOrderBy('m.fileid', 'DESC'); // tie-breaker

        // FETCH face detections
        $previews = $query->executeQuery()->fetchAll();
        if (empty($previews)) {
            return null;
        }

        // Score the face detections
        foreach ($previews as &$p) {
            // Get actual pixel size of face
            $iw = min(intval($p["image_width"] ?: 512), 2048);
            $ih = min(intval($p["image_height"] ?: 512), 2048);
            $w = floatval($p["width"]) * $iw;
            $h = floatval($p["height"]) * $ih;

            // Get center of face
            $x = floatval($p["x"]) + floatval($p["width"]) / 2;
            $y = floatval($p["y"]) + floatval($p["height"]) / 2;

            // 3D normal distribution - if the face is closer to the center, it's better
            $positionScore = exp(-pow($x - 0.5, 2) * 4) * exp(-pow($y - 0.5, 2) * 4);

            // Root size distribution - if the face is bigger, it's better,
            // but it doesn't matter beyond a certain point, especially 256px ;)
            $sizeScore = pow($w * 100, 1/4) * pow($h * 100, 1/4);

            // Combine scores
            $p["score"] = $positionScore * $sizeScore;
        }

        // Sort previews by score descending
        usort($previews, function($a, $b) {
            return $b["score"] <=> $a["score"];
        });

        return $previews;
    }
}