<?php
namespace OCA\Memories\Controller;

use OCP\IRequest;
use OCP\AppFramework\Services\IInitialState;
use OCP\AppFramework\Http\TemplateResponse;
use OCA\Viewer\Event\LoadViewer;
use OCA\Files\Event\LoadSidebar;
use OCP\AppFramework\Controller;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\Util;

use OCA\Memories\AppInfo\Application;

class PageController extends Controller {
    protected $userId;
    protected $appName;
    private IAppManager $appManager;
    protected IEventDispatcher $eventDispatcher;
    private IInitialState $initialState;
    private IUserSession $userSession;
    private IConfig $config;

    public function __construct(
        string $AppName,
        IRequest $request,
        $UserId,
        IAppManager $appManager,
        IEventDispatcher $eventDispatcher,
        IInitialState $initialState,
        IUserSession $userSession,
        IConfig $config) {

        parent::__construct($AppName, $request);
        $this->userId = $UserId;
        $this->appName = $AppName;
        $this->appManager = $appManager;
        $this->eventDispatcher = $eventDispatcher;
        $this->initialState = $initialState;
        $this->userSession = $userSession;
        $this->config = $config;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function main() {
        $user = $this->userSession->getUser();
        if (is_null($user)) {
            return null;
        }

        // Scripts
        Util::addScript($this->appName, 'memories-main');
        $this->eventDispatcher->dispatchTyped(new LoadSidebar());
        $this->eventDispatcher->dispatchTyped(new LoadViewer());

        // Configuration
        $uid = $user->getUid();
        $timelinePath = \OCA\Memories\Util::getPhotosPath($this->config, $uid);
        $this->initialState->provideInitialState('timelinePath', $timelinePath);
        $this->initialState->provideInitialState('showHidden',  $this->config->getUserValue(
            $uid, Application::APPNAME, 'showHidden', false));

        // Apps enabled
        $this->initialState->provideInitialState('systemtags', $this->appManager->isEnabledForUser('systemtags') === true);

        $response = new TemplateResponse($this->appName, 'main');
        return $response;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function folder() {
        return $this->main();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function favorites() {
        return $this->main();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function videos() {
        return $this->main();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function archive() {
        return $this->main();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function thisday() {
        return $this->main();
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function tags() {
        return $this->main();
    }
}
