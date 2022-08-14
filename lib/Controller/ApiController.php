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

namespace OCA\BetterPhotos\Controller;

use OCA\BetterPhotos\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends Controller {
	private IConfig $config;
	private IUserSession $userSession;
    private IDBConnection $connection;

	public function __construct(
		IRequest $request,
		IConfig $config,
		IUserSession $userSession,
        IDBConnection $connection
	) {
		parent::__construct(Application::APPNAME, $request);

		$this->config = $config;
		$this->userSession = $userSession;
        $this->connection = $connection;
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

        $list = \OCA\BetterPhotos\Db\Util::getDays($this->connection, $user->getUID());
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

        $list = \OCA\BetterPhotos\Db\Util::getDay($this->connection, $user->getUID(), intval($id));
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