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

namespace OCA\Memories\Controller;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\ClustersBackend\LensBackend;
use OCA\Memories\Db\FsManager;
use OCA\Memories\Db\LensFaces;
use OCA\Memories\Db\LensFolders;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Db\TimelineRoot;
use OCA\Memories\Exceptions;
use OCA\Memories\HttpResponseException;
use OCA\Memories\Service\Lens;
use OCA\Memories\Service\ServiceManager;
use OCA\Memories\Util;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Lock\LockedException;

final class LensController extends ApiController
{
    public function __construct(
        IRequest $request,
        protected IDBConnection $connection,
        protected TimelineQuery $tq,
        protected FsManager $fs,
        protected LensFolders $lensFolders,
        protected LensFaces $lensFaces,
        protected IClientService $clientService,
        protected Lens $lens,
        protected LensBackend $lensBackend,
        protected ServiceManager $serviceManager,
        protected Util $util,
    ) {
        parent::__construct(Application::APPNAME, $request);
    }

    /**
     * Serve file metadata and, for GET, raw bytes to the Lens service account.
     *
     * @param int $fileid file ID to serve
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function file(int $fileid): Http\Response
    {
        return $this->util->guardEx(function () use ($fileid) {
            $this->serviceManager->guardLensServiceAccount();
            $file = $this->serviceManager->getServiceFile($fileid);

            $response = new Http\Response();
            if ('HEAD' !== $this->request->getMethod()) {
                $handle = $file->fopen('rb');
                if (false === $handle) {
                    throw new \Exception("Failed to open file {$fileid}");
                }
                $response = new StreamResponse($handle);
            }

            $response->addHeader('Content-Type', $file->getMimeType());

            $meta = $this->getIndexMeta($fileid);
            if (null === $meta['parent_id']) {
                // The node exists but its filecache row vanished mid-flight:
                // transient, the daemon retries and discards on 404.
                throw new \Exception("Index metadata unavailable for {$fileid}");
            }
            $metadata = [
                'etag' => $file->getEtag(),
                'mtime' => $meta['mtime'],
                'parent_id' => $meta['parent_id'],
                'mimetype' => $file->getMimeType(),
                'epoch' => $meta['epoch'],
                'dayid' => $meta['dayid'],
                'places' => $this->getLensPlaces($fileid),
                'faces' => $this->getLensFaces($fileid),
                'owner' => $file->getStorage()->getId(),
            ];
            $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (\is_string($json)) {
                $response->addHeader('X-Memories-Metadata', base64_encode($json));
            }

            return $response;
        });
    }

    /**
     * Allocate the next catalog batch for daemon reconciliation.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function scan(): Http\Response
    {
        return $this->util->guardEx(function () {
            $this->serviceManager->guardLensServiceAccount();

            try {
                return new DataResponse($this->lensFolders->nextScanBatch());
            } catch (LockedException) {
                return new DataResponse([
                    'message' => 'Lens scan is busy',
                ], Http::STATUS_SERVICE_UNAVAILABLE, ['Retry-After' => '5']);
            }
        });
    }

    /**
     * Text search over the requesting user's own timeline via the Lens daemon.
     *
     * @param string $text  query text, must not be blank
     * @param int    $limit max hits, clamped to 1–500
     */
    #[NoAdminRequired]
    public function search(string $text = '', int $limit = 50): Http\Response
    {
        return $this->util->guardEx(function () use ($text, $limit) {
            if ('' === trim($text)) {
                throw new HttpResponseException(new DataResponse([
                    'message' => 'Search text must not be empty',
                ], Http::STATUS_BAD_REQUEST));
            }

            $base = $this->lens->daemonUrl();
            if ('' === $base) {
                throw new HttpResponseException(new DataResponse([
                    'message' => 'Lens daemon not configured',
                ], Http::STATUS_SERVICE_UNAVAILABLE));
            }

            // Folders derived server-side from the user, never trusted
            // from the client; matches the timeline scope, mounts included.
            $root = new TimelineRoot();
            $this->fs->populateRoot($root, true);

            if ($root->isEmpty()) {
                return new DataResponse([]);
            }

            $folders = $this->lensFolders->getFolderIds($root->getIds());
            if ([] === $folders) {
                return new DataResponse([]);
            }

            try {
                $res = $this->clientService->newClient()->post($base.'/v1/search', [
                    'json' => [
                        'text' => $text,
                        'folders' => $folders,
                        'limit' => min(500, max(1, $limit)),
                    ],
                    'timeout' => 10,
                    'nextcloud' => ['allow_local_address' => true],
                ]);

                $body = json_decode((string) $res->getBody(), true);
                $hits = $body['hits'] ?? [];
                if (!\is_array($hits)) {
                    $hits = [];
                }
            } catch (\Exception $e) {
                throw new HttpResponseException(new DataResponse([
                    'message' => 'Lens daemon unreachable',
                ], Http::STATUS_SERVICE_UNAVAILABLE));
            }

            // Missing keys map to null, never fatal on old points.
            return new DataResponse(array_map(static fn ($hit) => [
                'fileid' => (int) ($hit['fileid'] ?? 0),
                'score' => $hit['score'] ?? null,
                'w' => isset($hit['w']) ? (int) $hit['w'] : null,
                'h' => isset($hit['h']) ? (int) $hit['h'] : null,
                'etag' => $hit['etag'] ?? null,
                'mimetype' => $hit['mimetype'] ?? null,
                'epoch' => isset($hit['epoch']) ? (int) $hit['epoch'] : null,
                'dayid' => isset($hit['dayid']) ? (int) $hit['dayid'] : null,
            ], $hits));
        });
    }

    /**
     * Thin detection writeback for the Lens service account.
     *
     * Delete-all and recreate; geometry matching lives in the daemon.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function facesReplace(int $fileid, array $faces, string $owner): Http\Response
    {
        return $this->util->guardEx(function () use ($fileid, $faces, $owner) {
            $this->serviceManager->guardLensServiceAccount();

            return new DataResponse([
                'faces' => $this->lensFaces->replaceFaces($fileid, $faces, $owner),
            ]);
        });
    }

    /**
     * Due unassigned faces for the Lens service account, storage-ordered.
     *
     * @param int<1, 5000> $limit daemon batch cap; declared because the
     *                            framework default `limit` range (1-500) does not fit batch endpoints
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function facesBatch(int $limit = 1000): Http\Response
    {
        return $this->util->guardEx(function () use ($limit) {
            $this->serviceManager->guardLensServiceAccount();

            return new DataResponse([
                'faces' => $this->lensFaces->getDueBatch($limit),
            ]);
        });
    }

    /**
     * Grouping writeback for the Lens service account.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function facesClusters(array $assignments, array $attempted, string $owner): Http\Response
    {
        return $this->util->guardEx(function () use ($assignments, $attempted, $owner) {
            $this->serviceManager->guardLensServiceAccount();

            return new DataResponse([
                'assigned' => $this->lensFaces->applyClusters($assignments, $owner),
                'misses' => $this->lensFaces->backoffFaces($attempted),
            ]);
        });
    }

    /**
     * Delete one file's face rows, called back by the daemon once its vectors are gone.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function facesDelete(int $fileid): Http\Response
    {
        return $this->util->guardEx(function () use ($fileid) {
            $this->serviceManager->guardLensServiceAccount();

            $this->lensFaces->deleteFileFaces($fileid);

            return new DataResponse(['fileid' => $fileid, 'status' => 'deleted']);
        });
    }

    /**
     * Create an empty lens person with a server-minted id, optionally named.
     *
     * Ids are uint63: minted server-side because JS floats cannot hold them.
     */
    #[NoAdminRequired]
    public function personCreate(string $name = ''): Http\Response
    {
        return $this->util->guardEx(function () use ($name) {
            $this->guardLensEnabled();

            return new DataResponse($this->lensBackend->createPerson($name));
        });
    }

    /**
     * Rename a lens cluster or person to a viewer-scoped name.
     */
    #[NoAdminRequired]
    public function personRename(string $name, string $target): Http\Response
    {
        return $this->util->guardEx(function () use ($name, $target) {
            $this->guardLensEnabled();

            return new DataResponse(['name' => $this->lensBackend->renamePerson($name, $target)]);
        });
    }

    /**
     * Merge the source lens person or cluster into the target.
     */
    #[NoAdminRequired]
    public function personMerge(string $source, string $target): Http\Response
    {
        return $this->util->guardEx(function () use ($source, $target) {
            $this->guardLensEnabled();

            return new DataResponse(['name' => $this->lensBackend->mergePersons($source, $target)]);
        });
    }

    /**
     * Move faces to a lens person or cluster (target NULL unassigns).
     *
     * Moved ids are uint63: returned as strings, JS floats lose precision.
     *
     * @param array $faces raw face ids, cast in the backend
     */
    #[NoAdminRequired]
    public function personFacesMove(array $faces, ?string $target = null): Http\Response
    {
        return $this->util->guardEx(function () use ($faces, $target) {
            $this->guardLensEnabled();
            $moved = $this->lensBackend->moveFaces($faces, $target);

            return new DataResponse(['moved' => array_map(strval(...), $moved)]);
        });
    }

    /**
     * Remove a lens person: unassign its faces and drop the viewer's name.
     */
    #[NoAdminRequired]
    public function personDelete(string $name): Http\Response
    {
        return $this->util->guardEx(function () use ($name) {
            $this->guardLensEnabled();

            return new DataResponse($this->lensBackend->removePerson($name));
        });
    }

    /**
     * People mutations need the daemon: moves sync Qdrant directly.
     *
     * @throws \OCA\Memories\HttpResponseException
     */
    private function guardLensEnabled(): void
    {
        if (!$this->lensBackend->isEnabled()) {
            throw Exceptions::NotEnabled('Lens');
        }
    }

    /**
     * Catalog revision/dates and current storage parent, independent of the service user's mounts.
     *
     * Best-effort: failures never break file serving.
     *
     * @return array{epoch: ?int, dayid: ?int, parent_id: ?int, mtime: ?int}
     */
    private function getIndexMeta(int $fileid): array
    {
        try {
            $qb = $this->connection->getQueryBuilder();
            $qb->select('m.epoch', 'm.dayid', 'm.mtime', 'f.parent')
                ->from('filecache', 'f')
                ->leftJoin('f', 'memories', 'm', $qb->expr()->eq('f.fileid', 'm.fileid'))
                ->where($qb->expr()->eq('f.fileid', $qb->createNamedParameter($fileid, IQueryBuilder::PARAM_INT)))
            ;
            $row = $qb->executeQuery()->fetchAssociative();
            if (false !== $row) {
                return [
                    'epoch' => isset($row['epoch']) ? (int) $row['epoch'] : null,
                    'dayid' => isset($row['dayid']) ? (int) $row['dayid'] : null,
                    'parent_id' => (int) $row['parent'],
                    'mtime' => isset($row['mtime']) ? (int) $row['mtime'] : null,
                ];
            }
        } catch (\Throwable) {
        }

        return ['epoch' => null, 'dayid' => null, 'parent_id' => null, 'mtime' => null];
    }

    /**
     * Individual places of a file for the daemon, leaf first.
     *
     * Best-effort: failures never break file serving.
     *
     * @return list<array{osm_id: int, admin_level: int, name: string}>
     */
    private function getLensPlaces(int $fileid): array
    {
        try {
            return $this->tq->getPlacesById($fileid);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Current face rows of a file for restore stability.
     *
     * Best-effort: failures never break file serving.
     *
     * @return list<array{id: int, x: float, y: float, w: float, h: float, det_score: float, cluster_id: ?int, embed_version: int, next_try: int, retries: int}>
     */
    private function getLensFaces(int $fileid): array
    {
        try {
            return $this->lensFaces->getFacesByFileId($fileid);
        } catch (\Throwable) {
            return [];
        }
    }
}
