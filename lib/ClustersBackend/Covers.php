<?php

declare(strict_types=1);

namespace OCA\Memories\ClustersBackend;

use OCA\Memories\Db\SQL;
use OCA\Memories\Util;
use OCP\DB\QueryBuilder\IQueryBuilder;

class Covers
{
    /**
     * Select the list query to get covers.
     *
     * @param IQueryBuilder $query                Query builder
     * @param string        $type                 Cluster type
     * @param string        $clusterTable         Alias name for the cluster list
     * @param string        $clusterTableId       Column name for the cluster ID in clusterTable
     * @param string        $objectTable          Table name for the object mapping
     * @param string        $objectTableObjectId  Column name for the object ID in objectTable
     * @param string        $objectTableClusterId Column name for the cluster ID in objectTable
     * @param bool          $validateCluster      Whether to validate the cluster
     * @param bool          $validateFilecache    Whether to validate the filecache
     * @param mixed         $user                 Query expression for user ID to use for the covers
     */
    final public static function selectCover(
        IQueryBuilder &$query,
        string $type,
        string $clusterTable,
        string $clusterTableId,
        string $objectTable,
        string $objectTableObjectId,
        string $objectTableClusterId,
        bool $validateCluster = true,
        bool $validateFilecache = true,
        string $field = 'cover',
        mixed $user = null,
    ): void {
        // Clauses for the WHERE
        $clauses = [
            $query->expr()->eq('mcov.uid', $user ?? $query->expr()->literal(Util::getUser()->getUID())),
            $query->expr()->eq('mcov.clustertype', $query->expr()->literal($type)),
            $query->expr()->eq('mcov.clusterid', "{$clusterTable}.{$clusterTableId}"),
        ];

        // Subquery if the preview is still valid for this cluster
        if ($validateCluster) {
            $validSq = $query->getConnection()->getQueryBuilder();
            $validSq->select($validSq->expr()->literal(1))
                ->from($objectTable, 'cov_objs')
                ->where($validSq->expr()->eq($query->expr()->castColumn("cov_objs.{$objectTableObjectId}", IQueryBuilder::PARAM_INT), 'mcov.objectid'))
                ->andWhere($validSq->expr()->eq("cov_objs.{$objectTableClusterId}", "{$clusterTable}.{$clusterTableId}"))
            ;

            $clauses[] = SQL::exists($query, $validSq);
        }

        // Subquery if the file is still in the user's timeline tree
        if ($validateFilecache) {
            $treeSq = $query->getConnection()->getQueryBuilder();
            $treeSq->select($treeSq->expr()->literal(1))
                ->from('filecache', 'cov_f')
                ->innerJoin('cov_f', 'cte_folders', 'cov_cte_f', $treeSq->expr()->andX(
                    $treeSq->expr()->eq('cov_cte_f.fileid', 'cov_f.parent'),
                    $treeSq->expr()->eq('cov_cte_f.hidden', $treeSq->expr()->literal(0, \PDO::PARAM_INT)),
                ))
                ->where($treeSq->expr()->eq('cov_f.fileid', 'mcov.fileid'))
            ;

            $clauses[] = SQL::exists($query, $treeSq);
        }

        // Make subquery to select the cover
        $cvQ = $query->getConnection()->getQueryBuilder();
        $cvQ->select('mcov.objectid')
            ->from('memories_covers', 'mcov')
            ->where($cvQ->expr()->andX(...$clauses))
            ->setMaxResults(1)
        ;

        // SELECT the cover
        $query->selectAlias(SQL::subquery($query, $cvQ), $field);
    }

    /**
     * Filter the photos query to get only the cover for this user.
     *
     * @param IQueryBuilder $query                Query builder
     * @param string        $type                 Cluster type
     * @param string        $objectTable          Table name for the object mapping
     * @param string        $objectTableObjectId  Column name for the object ID in objectTable
     * @param string        $objectTableClusterId Column name for the cluster ID in objectTable
     */
    final public static function filterCover(
        IQueryBuilder &$query,
        string $type,
        string $objectTable,
        string $objectTableObjectId,
        string $objectTableClusterId,
    ): void {
        $query->innerJoin($objectTable, 'memories_covers', 'm_cov', $query->expr()->andX(
            $query->expr()->eq('m_cov.uid', $query->expr()->literal(Util::getUser()->getUID())),
            $query->expr()->eq('m_cov.clustertype', $query->expr()->literal($type)),
            $query->expr()->eq('m_cov.clusterid', "{$objectTable}.{$objectTableClusterId}"),
            $query->expr()->eq('m_cov.objectid', $query->expr()->castColumn("{$objectTable}.{$objectTableObjectId}", IQueryBuilder::PARAM_INT)),
        ));
    }

    /**
     * Set the cover photo for the given cluster.
     *
     * @param string $type      Cluster type
     * @param int    $clusterId Cluster ID
     * @param int    $objectId  Object ID
     * @param int    $fileid    File ID
     * @param bool   $manual    Whether this is a manual selection
     */
    final public static function setCover(string $type, int $clusterId, int $objectId, int $fileid, bool $manual): void
    {
        Util::transaction(static function () use ($type, $clusterId, $objectId, $fileid, $manual): void {
            $connection = \OC::$server->get(\OCP\IDBConnection::class);
            $query = $connection->getQueryBuilder();
            $query->delete('memories_covers')
                ->where($query->expr()->eq('uid', $query->createNamedParameter(Util::getUser()->getUID())))
                ->andWhere($query->expr()->eq('clustertype', $query->createNamedParameter($type)))
                ->andWhere($query->expr()->eq('clusterid', $query->createNamedParameter($clusterId)))
                ->executeStatement()
            ;

            $query = $connection->getQueryBuilder();
            $query->insert('memories_covers')
                ->values([
                    'uid' => $query->createNamedParameter(Util::getUser()->getUID()),
                    'clustertype' => $query->createNamedParameter($type),
                    'clusterid' => $query->createNamedParameter($clusterId),
                    'objectid' => $query->createNamedParameter($objectId),
                    'fileid' => $query->createNamedParameter($fileid),
                    'auto' => $query->createNamedParameter($manual ? 0 : 1, \PDO::PARAM_INT),
                    'timestamp' => $query->createNamedParameter(time(), \PDO::PARAM_INT),
                ])
                ->executeStatement()
            ;
        });
    }
}
