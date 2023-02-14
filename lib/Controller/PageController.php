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
        $pi = function ($key, $default) use ($uid) {
            $this->initialState->provideInitialState($key, $this->config->getUserValue(
                $uid,
                Application::APPNAME,
                $key,
                $default
            ));
        };

        // User configuration
        $pi('timelinePath', 'EMPTY');
        $pi('foldersPath', '/');
        $pi('showHidden', false);
        $pi('enableTopMemories', 'true');

        // Apps enabled
        $this->initialState->provideInitialState('systemtags', true === $this->appManager->isEnabledForUser('systemtags'));
        $this->initialState->provideInitialState('recognize', \OCA\Memories\Util::recognizeIsEnabled($this->appManager));
        $this->initialState->provideInitialState('facerecognitionInstalled', \OCA\Memories\Util::facerecognitionIsInstalled($this->appManager));
        $this->initialState->provideInitialState('facerecognitionEnabled', \OCA\Memories\Util::facerecognitionIsEnabled($this->config, $uid));
        $this->initialState->provideInitialState('albums', \OCA\Memories\Util::albumsIsEnabled($this->appManager));

        // Common state
        self::provideCommonInitialState($this->initialState);

        // Extra translations
        if (\OCA\Memories\Util::recognizeIsEnabled($this->appManager)) {
			// Auto translation for tags
			Util::addTranslations('recognize');
		}

        $response = new TemplateResponse($this->appName, 'main');
        $response->setContentSecurityPolicy(self::getCSP());
        $response->cacheFor(0);

        return $response;
    }

    /** Get the common content security policy */
    public static function getCSP()
    {
        // Image domains MUST be added to the connect domain list
        // because of the service worker fetch() call
        $addImageDomain = function ($url) use (&$policy) {
            $policy->addAllowedImageDomain($url);
            $policy->addAllowedConnectDomain($url);
        };

        // Create base policy
        $policy = new ContentSecurityPolicy();
        $policy->addAllowedWorkerSrcDomain("'self'");
        $policy->addAllowedScriptDomain("'self'");
        $policy->addAllowedFrameDomain("'self'");
        $policy->addAllowedImageDomain("'self'");
        $policy->addAllowedMediaDomain("'self'");
        $policy->addAllowedConnectDomain("'self'");

        // Video player
        $policy->addAllowedWorkerSrcDomain('blob:');
        $policy->addAllowedScriptDomain('blob:');
        $policy->addAllowedMediaDomain('blob:');

        // Image editor
        $policy->addAllowedConnectDomain('data:');

        // Allow OSM
        $policy->addAllowedFrameDomain('www.openstreetmap.org');
        $addImageDomain('https://*.tile.openstreetmap.org');
        $addImageDomain('https://*.a.ssl.fastly.net');

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
        $initialState->provideInitialState('video_default_quality', $config->getSystemValue('memories.video_default_quality', '0'));

        // Geo configuration
        $initialState->provideInitialState('places_gis', $config->getSystemValue('memories.gis_type', '-1'));
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
    public function places()
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
    public function map()
    {
        return $this->main();
    }
}
