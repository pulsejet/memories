<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use Psr\Log\LoggerInterface;
use OCP\IDBConnection;

trait TimelineWriteTags 
{
    protected IDBConnection $connection;
    protected LoggerInterface $logger;

    public function processTags(File $file, array $exif): void{
        foreach ($exif["TagsList"] as $tag) {
            $query = $this->connection->getQueryBuilder();
            $exists = $query->select('id')
                ->from('systemtag')
                ->where($query->expr()->eq('name', $query->createNamedParameter($tag,  IQueryBuilder::PARAM_STR)))
                ->executeQuery()
                ->fetch();

            $this->logger->error("fetched oc systemtag id", ["exists" => $exists]);

            if (!$exists) {
                $query = $this->connection->getQueryBuilder();
                $query->insert('systemtag')
                    ->values([
                         'name' => $query->createNamedParameter($tag, IQueryBuilder::PARAM_STR)
                    ])->executeStatement();
                $exists = $query->getLastInsertId();
            } 

            $this->logger->error("created oc systemtag id", ["exists" => $exists]);
            $this->logger->error("params", ["systemtagid" => $exists, "objectid" => $file->getId(), "objecttype" => "files"]);

            $query = $this->connection->getQueryBuilder();
            $params = [
                'systemtagid' => $query->createNamedParameter($exists, IQueryBuilder::PARAM_INT),
                'objectid' => $query->createNamedParameter($file->getId(), IQueryBuilder::PARAM_STR),
                'objecttype' => $query->createNamedParameter('files', IQueryBuilder::PARAM_STR),
            ];
            $result = $query->insert('systemtag_object_mapping')
                ->values($params)->executeStatement();

            $this->logger->error("inserted", [$tag, $result]);
        }
    }
}