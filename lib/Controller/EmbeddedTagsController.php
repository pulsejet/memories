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

use OCA\Memories\Db\EmbeddedTagsQuery;
use OCA\Memories\Db\FsManager;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Exceptions;
use OCA\Memories\Util;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class EmbeddedTagsController extends GenericApiController
{
    public function __construct(
        IRequest $request,
        IConfig $config,
        IUserSession $userSession,
        IDBConnection $connection,
        IRootFolder $rootFolder,
        IAppManager $appManager,
        LoggerInterface $logger,
        TimelineQuery $tq,
        FsManager $fs,
        protected EmbeddedTagsQuery $etq,
    ) {
        parent::__construct($request, $config, $userSession, $connection, $rootFolder, $appManager, $logger, $tq, $fs);
    }

    /**
     * Get tags in flat manner with optional filtering and pagination
     */
    #[NoAdminRequired]
    public function flat(): Http\Response
    {
        return Util::guardEx(function () {
            // Check if user is logged in
            if (!Util::isLoggedIn()) {
                throw Exceptions::NotLoggedIn();
            }

            // Get query parameters
            $pattern = $this->request->getParam('pattern');
            $limit = $this->request->getParam('limit');
            $offset = $this->request->getParam('offset');

            // Validate and sanitize parameters
            $limit = $limit !== null ? max(1, min(1000, (int) $limit)) : null;
            $offset = $offset !== null ? max(0, (int) $offset) : null;
            $pattern = $pattern !== null ? (string) $pattern : null;

            // Get tags
            $tags = $this->etq->getTagsFlat($pattern, $limit, $offset);

            // Get total count for pagination
            $totalCount = null;
            if ($limit !== null || $offset !== null) {
                $totalCount = $this->etq->getTagsCount($pattern);
            }

            // Prepare response
            $response = [
                'tags' => $tags,
            ];

            if ($totalCount !== null) {
                $response['pagination'] = [
                    'total' => $totalCount,
                    'limit' => $limit,
                    'offset' => $offset ?? 0,
                ];
            }

            return new JSONResponse($response, Http::STATUS_OK);
        });
    }

    /**
     * Get tags in hierarchical structure
     */
    #[NoAdminRequired]
    public function hierarchical(): Http\Response
    {
        return Util::guardEx(function () {
            // Check if user is logged in
            if (!Util::isLoggedIn()) {
                throw Exceptions::NotLoggedIn();
            }

            // Get query parameters
            $pattern = $this->request->getParam('pattern');
            $pattern = $pattern !== null ? (string) $pattern : null;

            // Get tags in hierarchical structure
            $tags = $this->etq->getTagsHierarchical($pattern);

            return new JSONResponse([
                'tags' => $tags,
                'structure' => 'hierarchical'
            ], Http::STATUS_OK);
        });
    }

    /**
     * Get tags count (useful for pagination info)
     */
    #[NoAdminRequired]
    public function count(): Http\Response
    {
        return Util::guardEx(function () {
            // Check if user is logged in
            if (!Util::isLoggedIn()) {
                throw Exceptions::NotLoggedIn();
            }

            // Get query parameters
            $pattern = $this->request->getParam('pattern');
            $pattern = $pattern !== null ? (string) $pattern : null;

            // Get count
            $count = $this->etq->getTagsCount($pattern);

            return new JSONResponse([
                'count' => $count,
                'pattern' => $pattern
            ], Http::STATUS_OK);
        });
    }
} 