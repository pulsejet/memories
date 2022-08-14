<?php
namespace OCA\BetterPhotos\Controller;

use OCP\IRequest;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;
use OCP\Util;

class PageController extends Controller {
	private $userId;
	protected $appName;

	public function __construct($AppName, IRequest $request, $UserId){
		parent::__construct($AppName, $request);
		$this->userId = $UserId;
		$this->appName = $AppName;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index() {
		Util::addScript($this->appName, 'betterphotos-main');
		Util::addStyle($this->appName, 'icons');

		$response = new TemplateResponse($this->appName, 'main');
		return $response;
	}

}
