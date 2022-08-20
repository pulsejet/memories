<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @author John Molakvoæ <skjnldsv@protonmail.com>
 *
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
 *
 */

namespace OCA\Memories\Controller;

use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchQuery;
use OCA\Memories\AppInfo\Application;
use OCA\Memories\Db\TimelineQuery;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Files\FileInfo;
use OCP\Files\Search\ISearchComparison;

class ApiController extends Controller {
	private IConfig $config;
	private IUserSession $userSession;
    private IDBConnection $connection;
	private IRootFolder $rootFolder;
	private TimelineQuery $timelineQuery;

	public function __construct(
		IRequest $request,
		IConfig $config,
		IUserSession $userSession,
        IDBConnection $connection,
		IRootFolder $rootFolder,
	) {
		parent::__construct(Application::APPNAME, $request);

		$this->config = $config;
		$this->userSession = $userSession;
        $this->connection = $connection;
		$this->timelineQuery = new TimelineQuery($this->connection);
		$this->rootFolder = $rootFolder;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 */
	public function days(): JSONResponse {
        $user = $this->userSession->getUser();
		if (is_null($user)) {
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
		}

        $list = $this->timelineQuery->getDays($this->config, $user->getUID());
		return new JSONResponse($list, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 */
	public function day(string $id): JSONResponse {
        $user = $this->userSession->getUser();
		if (is_null($user) || !is_numeric($id)) {
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
		}

        $list = $this->timelineQuery->getDay($this->config, $user->getUID(), intval($id));
		return new JSONResponse($list, Http::STATUS_OK);
	}

	/**
	 * Check if folder is allowed and get it if yes
	 */
	private function getAllowedFolder(int $folder, $user) {
		// Get root if folder not specified
		$root = $this->rootFolder->getUserFolder($user->getUID());
		if ($folder === 0) {
			$folder = $root->getId();
		}

		// Check access to folder
		$nodes = $root->getById($folder);
		if (empty($nodes)) {
			return NULL;
		}

		// Check it is a folder
		$node = $nodes[0];
		if (!$node instanceof \OCP\Files\Folder) {
			return NULL;
		}

		return $node;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 */
	public function folder(string $folder): JSONResponse {
        $user = $this->userSession->getUser();
		if (is_null($user) || !is_numeric($folder)) {
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
		}

		// Check permissions
		$node = $this->getAllowedFolder(intval($folder), $user);
		if (is_null($node)) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		// Get response from db
        $list = $this->timelineQuery->getDaysFolder($node->getId());

		// Get subdirectories
		$sub = $node->search(new SearchQuery(
			new SearchComparison(ISearchComparison::COMPARE_EQUAL, 'mimetype', FileInfo::MIMETYPE_FOLDER),
			0, 0, [], $user));
		$sub = array_filter($sub, function ($item) use ($node) {
			return $item->getParent()->getId() === $node->getId();
		});

		// Sort by name
		usort($sub, function($a, $b) {
			return strnatcmp($a->getName(), $b->getName());
		});

		// Map sub to JSON array
		$subdirArray = [
			"dayid" => -0.1,
			"detail" => array_map(function ($node) {
				return [
					"fileid" => $node->getId(),
					"name" => $node->getName(),
					"is_folder" => 1,
					"path" => $node->getPath(),
				];
			}, $sub, []),
		];
		$subdirArray["count"] = count($subdirArray["detail"]);
		array_unshift($list, $subdirArray);

		return new JSONResponse($list, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 */
	public function folderDay(string $folder, string $dayId): JSONResponse {
        $user = $this->userSession->getUser();
		if (is_null($user) || !is_numeric($folder) || !is_numeric($dayId)) {
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
		}

		$node = $this->getAllowedFolder(intval($folder), $user);
		if ($node === NULL) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

        $list = $this->timelineQuery->getDayFolder($node->getId(), intval($dayId));
		return new JSONResponse($list, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 *
	 * update preferences (user setting)
	 *
	 * @param string key the identifier to change
	 * @param string value the value to set
	 *
	 * @return JSONResponse an empty JSONResponse with respective http status code
	 */
	public function setUserConfig(string $key, string $value): JSONResponse {
		$user = $this->userSession->getUser();
		if (is_null($user)) {
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
		}

		$userId = $user->getUid();
		$this->config->setUserValue($userId, Application::APPNAME, $key, $value);
		return new JSONResponse([], Http::STATUS_OK);
	}
}