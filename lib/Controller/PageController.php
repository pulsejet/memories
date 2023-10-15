<?php

namespace OCA\Memories\Controller;

use OCA\Files\Event\LoadSidebar;
use OCA\Memories\AppInfo\Application;
use OCA\Memories\Service\BinExt;
use OCA\Memories\Util;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;

class PageController extends Controller
{
    public function __construct(
        IRequest $request,
        protected IEventDispatcher $eventDispatcher
    ) {
        parent::__construct(Application::APPNAME, $request);
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function main()
    {
        // Check native version if available
        $nativeVer = Util::callerNativeVersion();
        if (null !== $nativeVer && version_compare($nativeVer, BinExt::NX_VER_MIN, '<')) {
            return new PublicTemplateResponse(Application::APPNAME, 'native-old');
        }

        // Scripts
        \OCP\Util::addScript(Application::APPNAME, 'memories-main');

        // Extra translations
        if (Util::recognizeIsEnabled()) {
            // Auto translation for tags
            \OCP\Util::addTranslations('recognize');
        }

        $response = new TemplateResponse(Application::APPNAME, 'main', self::getMainParams());
        $response->setContentSecurityPolicy(self::getCSP());
        $response->cacheFor(0);

        // Check if requested from native app
        if (!Util::callerIsNative()) {
            $this->eventDispatcher->dispatchTyped(new LoadSidebar());
        }

        return $response;
    }

    /** Get the common content security policy */
    public static function getCSP()
    {
        // Image domains MUST be added to the connect domain list
        // because of the service worker fetch() call
        $addImageDomain = static function ($url) use (&$policy): void {
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

        // Native communication
        $addImageDomain('http://127.0.0.1');

        // Allow Nominatim
        $policy->addAllowedConnectDomain('nominatim.openstreetmap.org');

        return $policy;
    }

    /**
     * Get params for main.php template.
     */
    public static function getMainParams(): array
    {
        return [
            'native' => Util::callerIsNative(),
        ];
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

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function explore()
    {
        return $this->main();
    }

    /**
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     */
    public function nxsetup()
    {
        return $this->main();
    }
}
