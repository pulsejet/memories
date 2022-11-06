<?php

namespace OCA\Memories\Controller;

use OCA\Files\Event\LoadSidebar;
use OCA\Memories\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller
{
    protected $userId;
    protected $appName;
    protected IEventDispatcher $eventDispatcher;
    private IAppManager $appManager;
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
        IConfig $config
    ) {
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
     *
     * @NoCSRFRequired
     */
    public function main()
    {
        $user = $this->userSession->getUser();
        if (null === $user) {
            return null;
        }

        // Scripts
        Util::addScript($this->appName, 'memories-main');
        $this->eventDispatcher->dispatchTyped(new LoadSidebar());

        // Configuration
        $uid = $user->getUid();
        $this->initialState->provideInitialState('timelinePath', $this->config->getUserValue(
            $uid,
            Application::APPNAME,
            'timelinePath',
            'EMPTY'
        ));
        $this->initialState->provideInitialState('foldersPath', $this->config->getUserValue(
            $uid,
            Application::APPNAME,
            'foldersPath',
            '/'
        ));
        $this->initialState->provideInitialState('showHidden', $this->config->getUserValue(
            $uid,
            Application::APPNAME,
            'showHidden',
            false
        ));

        // Apps enabled
        $this->initialState->provideInitialState('systemtags', true === $this->appManager->isEnabledForUser('systemtags'));
        $this->initialState->provideInitialState('maps', true === $this->appManager->isEnabledForUser('maps'));
        $this->initialState->provideInitialState('recognize', \OCA\Memories\Util::recognizeIsEnabled($this->appManager));
        $this->initialState->provideInitialState('albums', \OCA\Memories\Util::albumsIsEnabled($this->appManager));

        // App version
        $this->initialState->provideInitialState('version', $this->appManager->getAppInfo('memories')['version']);

        $policy = new ContentSecurityPolicy();
        $policy->addAllowedWorkerSrcDomain("'self'");
        $policy->addAllowedScriptDomain("'self'");

        $response = new TemplateResponse($this->appName, 'main');
        $response->setContentSecurityPolicy($policy);

        return $response;
    }

    /**
     * @PublicPage
     *
     * @NoCSRFRequired
     */
    public function sharedfolder(string $token)
    {
        // Scripts
        Util::addScript($this->appName, 'memories-main');
        $this->eventDispatcher->dispatchTyped(new LoadSidebar());

        // App version
        $this->initialState->provideInitialState('version', $this->appManager->getAppInfo('memories')['version']);

        $policy = new ContentSecurityPolicy();
        $policy->addAllowedWorkerSrcDomain("'self'");
        $policy->addAllowedScriptDomain("'self'");

        $response = new PublicTemplateResponse($this->appName, 'main');
        $response->setContentSecurityPolicy($policy);

        return $response;
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function folder()
    {
        return $this->main();
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function favorites()
    {
        return $this->main();
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function albums()
    {
        return $this->main();
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function videos()
    {
        return $this->main();
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function archive()
    {
        return $this->main();
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function thisday()
    {
        return $this->main();
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function people()
    {
        return $this->main();
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function tags()
    {
        return $this->main();
    }
}
