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

use OCA\Memories\Db\TimelineRoot;
use OCA\Memories\Errors;
use OCA\Memories\HttpResponseException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

abstract class GenericClusterController extends GenericApiController
{
    protected ?TimelineRoot $root;

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * Get list of clusters
     */
    public function list(): Http\Response
    {
        try {
            $this->init();

            // Get cluster list that will directly be returned as JSON
            $list = $this->getClusters();

            return new JSONResponse($list, Http::STATUS_OK);
        } catch (HttpResponseException $e) {
            return $e->response;
        } catch (\Exception $e) {
            return Errors::Generic($e);
        }
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * Get preview for a cluster
     */
    public function preview(string $name): Http\Response
    {
        try {
            $this->init();

            // Get list of some files in this cluster
            $fileIds = $this->getFileIds($name, 8);

            // If no files found then return 404
            if (0 === \count($fileIds)) {
                return new JSONResponse([], Http::STATUS_NOT_FOUND);
            }

            // Shuffle the list so we don't always get the same preview
            shuffle($fileIds);

            // Get preview from image list
            return $this->getPreviewFromImageList($fileIds);
        } catch (HttpResponseException $e) {
            return $e->response;
        } catch (\Exception $e) {
            return Errors::Generic($e);
        }
    }

    /**
     * @NoAdminRequired
     *
     * @UseSession
     *
     * Download a cluster as a zip file
     */
    public function download(string $name): Http\Response
    {
        try {
            $this->init();

            // Get list of all files in this cluster
            $fileIds = $this->getFileIds($name);

            // Get download handle
            $filename = $this->clusterName($name);
            $handle = \OCA\Memories\Controller\DownloadController::createHandle($filename, $fileIds);

            return new JSONResponse(['handle' => $handle], Http::STATUS_OK);
        } catch (HttpResponseException $e) {
            return $e->response;
        } catch (\Exception $e) {
            return Errors::Generic($e);
        }
    }

    /**
     * A human-readable name for the app.
     * Used for error messages.
     */
    abstract protected function appName(): string;

    /**
     * Whether the app is enabled for the current user.
     */
    abstract protected function isEnabled(): bool;

    /**
     * Get the cluster list for the current user.
     */
    abstract protected function getClusters(): array;

    /**
     * Get a list of fileids for the given cluster for preview generation.
     *
     * @param string $name  Identifier for the cluster
     * @param int    $limit Maximum number of fileids to return
     *
     * @return int[]
     */
    abstract protected function getFileIds(string $name, ?int $limit = null): array;

    /**
     * Initialize and check if the app is enabled.
     * Gets the root node if required.
     */
    protected function init(): void
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            throw new HttpResponseException(Errors::NotLoggedIn());
        }

        if (!$this->isEnabled()) {
            throw new HttpResponseException(Errors::NotEnabled($this->appName()));
        }

        $this->root = null;
        if ($this->useTimelineRoot()) {
            $this->root = $this->getRequestRoot();
            if ($this->root->isEmpty()) {
                throw new HttpResponseException(Errors::NoRequestRoot());
            }
        }
    }

    /**
     * Should the timeline root be queried?
     */
    protected function useTimelineRoot(): bool
    {
        return true;
    }

    /**
     * Human readable name for the cluster.
     */
    protected function clusterName(string $name)
    {
        return $name;
    }
}
