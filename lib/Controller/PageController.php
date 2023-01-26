<?php

namespace OCA\Memories\Controller;

use OCA\Files\Event\LoadSidebar;
use OCA\Memories\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
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
        $uid = $user->getUID();
        $this->initialState->provideInitialState(
            'timelinePath',
            $this->config->getUserValue(
            $uid,
            Application::APPNAME,
            'timelinePath',
            'EMPTY'
        )
        );
        $this->initialState->provideInitialState(
            'foldersPath',
            $this->config->getUserValue(
            $uid,
            Application::APPNAME,
            'foldersPath',
            '/'
        )
        );
        $this->initialState->provideInitialState(
            'showHidden',
            $this->config->getUserValue(
            $uid,
            Application::APPNAME,
            'showHidden',
            false
        )
        );

        // Apps enabled
        $this->initialState->provideInitialState('systemtags', true === $this->appManager->isEnabledForUser('systemtags'));
        $this->initialState->provideInitialState('maps', true === $this->appManager->isEnabledForUser('maps'));
        $this->initialState->provideInitialState('recognize', \OCA\Memories\Util::recognizeIsEnabled($this->appManager));
        $this->initialState->provideInitialState('facerecognitionInstalled', \OCA\Memories\Util::facerecognitionIsInstalled($this->appManager));
        $this->initialState->provideInitialState('facerecognitionEnabled', \OCA\Memories\Util::facerecognitionIsEnabled($this->config, $uid));
        $this->initialState->provideInitialState('albums', \OCA\Memories\Util::albumsIsEnabled($this->appManager));

        // Common state
        self::provideCommonInitialState($this->initialState);

        $response = new TemplateResponse($this->appName, 'main');
        $response->setContentSecurityPolicy(self::getCSP());

        return $response;
    }

    /** Get the common content security policy */
    public static function getCSP()
    {
        $policy = new ContentSecurityPolicy();
        $policy->addAllowedWorkerSrcDomain("'self'");
        $policy->addAllowedScriptDomain("'self'");

        // Video player
        $policy->addAllowedWorkerSrcDomain('blob:');
        $policy->addAllowedScriptDomain('blob:');
        $policy->addAllowedMediaDomain('blob:');

        // Image editor
        $policy->addAllowedConnectDomain('data:');

        // Allow nominatim for metadata
        $policy->addAllowedConnectDomain('nominatim.openstreetmap.org');
        $policy->addAllowedFrameDomain('www.openstreetmap.org');
        $policy->addAllowedImageDomain('https://*.tile.openstreetmap.org');

        return $policy;
    }

    /** Provide initial state for all pages */
    public static function provideCommonInitialState(IInitialState &$initialState)
    {
        $appManager = \OC::$server->get(\OCP\App\IAppManager::class);
        $config = \OC::$server->get(\OCP\IConfig::class);

        // App version
        $initialState->provideInitialState('version', $appManager->getAppInfo('memories')['version']);

        // Video configuration
        $initialState->provideInitialState('notranscode', $config->getSystemValue('memories.no_transcode', 'UNSET'));
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
    public function recognize()
    {
        return $this->main();
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function facerecognition()
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

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function locations()
    {
        return $this->main();
    }
}
