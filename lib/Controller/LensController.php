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
use OCA\Memories\Db\FsManager;
use OCA\Memories\Db\LensFolders;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Db\TimelineRoot;
use OCA\Memories\Exceptions;
use OCA\Memories\HttpResponseException;
use OCA\Memories\Service\Lens;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;

final class LensController extends ApiController
{
    public function __construct(
        IRequest $request,
        protected IUserSession $userSession,
        protected IDBConnection $connection,
        protected IRootFolder $rootFolder,
        protected TimelineQuery $tq,
        protected FsManager $fs,
    ) {
        parent::__construct(Application::APPNAME, $request);
    }

    /**
     * Serve raw file bytes to the Lens service account by fileid.
     *
     * @param int $fileid file ID to serve
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function file(int $fileid): Http\Response
    {
        return Util::guardEx(function () use ($fileid) {
            $this->guardServiceAccount();

            if ($fileid <= 0) {
                throw Exceptions::NotFoundFile($fileid);
            }

            try {
                // getById only sees set-up mounts, which are the caller's;
                // find a user whose mounts contain this file and look up inside theirs.
                $userFolder = $this->rootFolder->getUserFolder($this->getFileOwner($fileid));
                $nodes = $userFolder->getById($fileid);
            } catch (NotFoundException $e) {
                throw Exceptions::NotFoundFile($fileid);
            }

            $file = null;
            foreach ($nodes as $node) {
                if ($node instanceof File) {
                    $file = $node;

                    break;
                }
            }

            if (null === $file) {
                throw Exceptions::NotFoundFile($fileid);
            }

            $handle = $file->fopen('rb');
            if (false === $handle) {
                throw new \Exception("Failed to open file {$fileid}");
            }

            $response = new StreamResponse($handle);
            $response->addHeader('Content-Type', $file->getMimeType());

            $meta = $this->getIndexMeta($fileid);
            $metadata = [
                'etag' => $file->getEtag(),
                'mimetype' => $file->getMimeType(),
                'epoch' => $meta['epoch'],
                'dayid' => $meta['dayid'],
                'places' => $this->getLensPlaces($fileid),
            ];
            $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (\is_string($json)) {
                $response->addHeader('X-Memories-Metadata', base64_encode($json));
            }

            return $response;
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
        return Util::guardEx(function () use ($text, $limit) {
            if ('' === trim($text)) {
                throw new HttpResponseException(new DataResponse([
                    'message' => 'Search text must not be empty',
                ], Http::STATUS_BAD_REQUEST));
            }

            $base = Lens::daemonUrl();
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

            $folders = \OC::$server->get(LensFolders::class)->getFolderIds($root->getIds());
            if ([] === $folders) {
                return new DataResponse([]);
            }

            try {
                $res = \OC::$server->get(IClientService::class)->newClient()->post($base.'/v1/search', [
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
     * Epoch and dayid of a file from the memories table, nulls when unknown.
     *
     * Best-effort: failures never break file serving.
     *
     * @return array{epoch: ?int, dayid: ?int}
     */
    private function getIndexMeta(int $fileid): array
    {
        try {
            $qb = $this->connection->getQueryBuilder();
            $qb->select('epoch', 'dayid')
                ->from('memories')
                ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileid, IQueryBuilder::PARAM_INT)))
            ;
            $row = $qb->executeQuery()->fetch();
            if (false !== $row) {
                return [
                    'epoch' => isset($row['epoch']) ? (int) $row['epoch'] : null,
                    'dayid' => isset($row['dayid']) ? (int) $row['dayid'] : null,
                ];
            }
        } catch (\Throwable) {
        }

        return ['epoch' => null, 'dayid' => null];
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
     * UID of a user whose mounts contain this fileid.
     *
     * Any mount serves identical bytes; the auth guard already ran,
     * so this reveals nothing about the file to the caller.
     *
     * @param int $fileid file ID to resolve an owner for
     */
    private function getFileOwner(int $fileid): string
    {
        $mountCache = \OC::$server->get(IUserMountCache::class);
        foreach ($mountCache->getMountsForFileId($fileid) as $info) {
            return $info->getUser()->getUID();
        }

        throw Exceptions::NotFoundFile($fileid);
    }

    /**
     * Only the configured Lens service account may call this endpoint.
     *
     * Runs before any file lookup, so failures never reveal file existence.
     */
    private function guardServiceAccount(): void
    {
        $serviceUser = SystemConfig::get('memories.lens.service_user');

        $user = $this->userSession->getUser();
        if (null === $user) {
            throw new HttpResponseException(new DataResponse([
                'message' => 'Unauthorized',
            ], Http::STATUS_UNAUTHORIZED));
        }

        if ('' === $serviceUser || $user->getUID() !== $serviceUser) {
            throw new HttpResponseException(new DataResponse([
                'message' => 'Forbidden',
            ], Http::STATUS_FORBIDDEN));
        }
    }
}
