<?php
declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;

trait TimelineQueryFaces {
    protected IDBConnection $connection;

    public function transformFaceFilter(IQueryBuilder &$query, string $userId, string $faceName) {
        // Get title and uid of face user
        $faceNames = explode('/', $faceName);
        if (count($faceNames) !== 2) return;
        $faceUid = $faceNames[0];
        $faceName = $faceNames[1];

        // Get cluster ID
        $sq = $query->getConnection()->getQueryBuilder();
        $id = $sq->select('id')->from('recognize_face_clusters')
            ->where($query->expr()->eq('user_id', $sq->createNamedParameter($faceUid)))
            ->andWhere($query->expr()->eq('title', $sq->createNamedParameter($faceName)))
            ->executeQuery()->fetchOne();
        if (!$id) return;

        // Join with cluster
        $query->innerJoin('m', 'recognize_face_detections', 'rfd', $query->expr()->andX(
            $query->expr()->eq('rfd.file_id', 'm.fileid'),
            $query->expr()->eq('rfd.cluster_id', $query->createNamedParameter($id)),
        ));
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
        $query->orderBy('count', 'DESC');

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

    public function getFacePreviews(Folder $folder) {
        $query = $this->connection->getQueryBuilder();

        // Windowing
        $rowNumber = $query->createFunction('ROW_NUMBER() OVER (PARTITION BY rfd.cluster_id) as n');

        // SELECT face detections for ID
        $query->select(
            'rfd.cluster_id',
            'rfd.file_id',
            'rfd.x', 'rfd.y', 'rfd.width', 'rfd.height',
            'f.etag',
            $rowNumber,
        )->from('recognize_face_detections', 'rfd');
        $query->where($query->expr()->isNotNull('rfd.cluster_id'));

        // WHERE these photos are memories indexed
        $query->innerJoin('rfd', 'memories', 'm', $query->expr()->eq('m.fileid', 'rfd.file_id'));

        // WHERE these photos are in the user's requested folder recursively
        $query->innerJoin('m', 'filecache', 'f', $this->getFilecacheJoinQuery($query, $folder, true, false));

        // Make this a sub query
        $fun = $query->createFunction('(' . $query->getSQL() . ')');

        // Create outer query
        $outerQuery = $this->connection->getQueryBuilder();
        $outerQuery->setParameters($query->getParameters());
        $outerQuery->select('*')->from($fun, 't');
        $outerQuery->where($query->expr()->lte('t.n', $outerQuery->createParameter('nc')));
        $outerQuery->setParameter('nc', 4, IQueryBuilder::PARAM_INT);

        // FETCH all face detections
        $previews = $outerQuery->executeQuery()->fetchAll();

        // Post-process, everthing is a number
        foreach($previews as &$row) {
            $row["cluster_id"] = intval($row["cluster_id"]);
            $row["fileid"] = intval($row["file_id"]);
            $row["x"] = floatval($row["x"]);
            $row["y"] = floatval($row["y"]);
            $row["width"] = floatval($row["width"]);
            $row["height"] = floatval($row["height"]);

            // remove stale
            unset($row["file_id"]);
            unset($row["n"]);
        }

        return $previews;
    }
}