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

namespace OCA\Polaroid\Controller;

use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchQuery;
use OCA\Polaroid\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\ContentSecurityPolicy;
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
	private \OCA\Polaroid\Db\Util $util;
	private IRootFolder $rootFolder;

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
		$this->util = new \OCA\Polaroid\Db\Util($this->connection);
		$this->rootFolder = $rootFolder;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function days(): JSONResponse {
        $user = $this->userSession->getUser();
		if (is_null($user)) {
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
		}

        $list = $this->util->getDays($user->getUID());
		return new JSONResponse($list, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 */
	public function day(string $id): JSONResponse {
        $user = $this->userSession->getUser();
		if (is_null($user) || !is_numeric($id)) {
			return new JSONResponse([], Http::STATUS_PRECONDITION_FAILED);
		}

        $list = $this->util->getDay($user->getUID(), intval($id));
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
	 * @NoCSRFRequired
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
        $list = $this->util->getDaysFolder($node->getId());

		// Get subdirectories
		$sub = $node->search(new SearchQuery(
			new SearchComparison(ISearchComparison::COMPARE_EQUAL, 'mimetype', FileInfo::MIMETYPE_FOLDER),
			0, 0, [], $user));
		$sub = array_filter($sub, function ($item) use ($node) {
			return $item->getParent()->getId() === $node->getId();
		});

		// Map sub to JSON array
		$subdirArray = [
			"day_id" => -0.1,
			"detail" => array_map(function ($item) {
				return [
					"file_id" => $item->getId(),
					"name" => $item->getName(),
					"is_folder" => 1,
				];
			}, $sub, []),
		];
		$subdirArray["count"] = count($subdirArray["detail"]);
		array_unshift($list, $subdirArray);

		return new JSONResponse($list, Http::STATUS_OK);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
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

        $list = $this->util->getDayFolder($node->getId(), intval($dayId));
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

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function serviceWorker(): StreamResponse {
		$response = new StreamResponse(__DIR__.'/../../js/photos-service-worker.js');
		$response->setHeaders([
			'Content-Type' => 'application/javascript',
			'Service-Worker-Allowed' => '/'
		]);
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedWorkerSrcDomain("'self'");
		$policy->addAllowedScriptDomain("'self'");
		$policy->addAllowedConnectDomain("'self'");
		$response->setContentSecurityPolicy($policy);
		return $response;
	}
}