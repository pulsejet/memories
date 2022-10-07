<?php
declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;

trait TimelineQueryFaces {
    protected IDBConnection $connection;

    public function transformFaceFilter(IQueryBuilder &$query, string $userId, int $faceId) {
        $query->innerJoin('m', 'recognize_face_detections', 'rfd', $query->expr()->andX(
            $query->expr()->eq('rfd.file_id', 'm.fileid'),
            $query->expr()->eq('rfd.cluster_id', $query->createNamedParameter($faceId)),
        ));
    }

    public function getFaces(Folder $folder) {
        $query = $this->connection->getQueryBuilder();

        // SELECT all face clusters
        $count = $query->func()->count($query->createFunction('DISTINCT m.fileid'), 'count');
        $query->select('rfc.id', 'rfc.title', $count)->from('recognize_face_clusters', 'rfc');

        // WHERE there are faces with this cluster
        $query->innerJoin('rfc', 'recognize_face_detections', 'rfd', $query->expr()->eq('rfc.id', 'rfd.cluster_id'));

        // WHERE these items are memories indexed photos
        $query->innerJoin('rfd', 'memories', 'm', $query->expr()->eq('m.fileid', 'rfd.file_id'));

        // WHERE these photos are in the user's requested folder recursively
        $query->innerJoin('m', 'filecache', 'f', $this->getFilecacheJoinQuery($query, $folder, true, false));

        // GROUP by ID of face cluster
        $query->groupBy('rfc.id');

        // ORDER by number of faces in cluster
        $query->orderBy('count', 'DESC');

        // FETCH all faces
        $faces = $query->executeQuery()->fetchAll();

        // Post process
        foreach($faces as &$row) {
            $row["name"] = $row["title"];
            unset($row["title"]);
            $row["count"] = intval($row["count"]);
        }

        return $faces;
    }

    public function getFacePreviews(Folder $folder, int $faceId) {
        $query = $this->connection->getQueryBuilder();

        // SELECT face detections for ID
        $query->select('rfd.file_id', 'rfd.x', 'rfd.y', 'rfd.width', 'rfd.height', 'f.etag')->from('recognize_face_detections', 'rfd');
        $query->where($query->expr()->eq('rfd.cluster_id', $query->createNamedParameter($faceId)));

        // WHERE these photos are memories indexed
        $query->innerJoin('rfd', 'memories', 'm', $query->expr()->eq('m.fileid', 'rfd.file_id'));

        // WHERE these photos are in the user's requested folder recursively
        $query->innerJoin('m', 'filecache', 'f', $this->getFilecacheJoinQuery($query, $folder, true, false));

        // MAX 4 results
        $query->setMaxResults(4);

        // FETCH all face detections
        $previews = $query->executeQuery()->fetchAll();

        // Post-process, everthing is a number
        foreach($previews as &$row) {
            $row["fileid"] = intval($row["file_id"]);
            unset($row["file_id"]);
            $row["x"] = floatval($row["x"]);
            $row["y"] = floatval($row["y"]);
            $row["width"] = floatval($row["width"]);
            $row["height"] = floatval($row["height"]);
        }

        return $previews;
    }
}