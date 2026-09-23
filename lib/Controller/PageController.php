<?php

declare(strict_types=1);

namespace OCA\Memories\Controller;

use OCA\Files\Event\LoadSidebar;
use OCA\Memories\AppInfo\Application;
use OCA\Memories\Service\BinExt;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

final class PageController extends Controller
{
    public function __construct(
        IRequest $request,
        protected IEventDispatcher $eventDispatcher,
        private IInitialState $initialState,
        private LoggerInterface $logger,
        private IUserSession $userSession,
        private ?\OCA\Recognize\Public\ApiKeyManager $apiKeyManager,
        protected SystemConfig $systemConfig,
    ) {
        parent::__construct(Application::APPNAME, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function main(): Response
    {
        // Check native version if available
        $nativeVer = Util::callerNativeVersion();
        if (null !== $nativeVer && version_compare($nativeVer, BinExt::NX_VER_MIN, '<')) {
            return new PublicTemplateResponse(Application::APPNAME, 'native-old');
        }

        // Scripts
        \OCP\Util::addScript(Application::APPNAME, 'memories-main');

        // Additional setup for Recognize
        if ($this->systemConfig->recognizeIsInstalled()) {
            // Auto translation for tags
            \OCP\Util::addTranslations('recognize');
            // Obtain API Key
            if (null !== $this->apiKeyManager) {
                try {
                    $this->initialState->provideInitialState('recognizeApiKey', $this->apiKeyManager->generateApiKey());
                } catch (\JsonException $e) {
                    $this->logger->error('Failed to generate recognize api key', ['exception' => $e]);
                }
            }
        }

        $response = new TemplateResponsePatch(Application::APPNAME, 'main', self::getMainParams());
        $response->setContentSecurityPolicy($this->systemConfig->getCSP());
        $response->cacheFor(0);

        // Check if requested from native app
        if (!Util::callerIsNative()) {
            $this->eventDispatcher->dispatchTyped(new LoadSidebar());
        }

        return $response;
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

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function folder(): Response
    {
        return $this->main();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function favorites(): Response
    {
        return $this->main();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function albums(): Response
    {
        return $this->main();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function videos(): Response
    {
        return $this->main();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function archive(): Response
    {
        return $this->main();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function thisday(): Response
    {
        return $this->main();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function recognize(): Response
    {
        return $this->main();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function facerecognition(): Response
    {
        return $this->main();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function places(): Response
    {
        return $this->main();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function tags(): Response
    {
        return $this->main();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function map(): Response
    {
        return $this->main();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function explore(): Response
    {
        return $this->main();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function search(): Response
    {
        return $this->main();
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function nxsetup(): Response
    {
        return $this->main();
    }

    /**
     * Get <link> headers from apps (theme stylesheets, icons, ...).
     *
     * Dispatches BeforeTemplateRenderedEvent first so apps (e.g. theming)
     * inject their headers like on a normal page.
     *
     * There is no OCP API to read back headers added via OCP\Util::addHeader;
     * core reads the same static in OC\Template\Template::fetchPage.
     * Psalm reports no issue for this read.
     *
     * @return array<array<string, null|string>>
     */
    public function getLinkHeaders(): array
    {
        $user = $this->userSession->getUser();
        $this->eventDispatcher->dispatchTyped(new BeforeTemplateRenderedEvent(
            null !== $user,
            new TemplateResponse(Application::APPNAME, 'main', [], TemplateResponse::RENDER_AS_BLANK),
        ));

        $cssLinks = [
            ['rel' => 'stylesheet', 'href' => \OC::$WEBROOT.'/core/css/server.css'],
        ];
        foreach (\OC_Util::$headers as $header) {
            if (($header['tag'] ?? null) !== 'link' || !isset($header['attributes']['href'])) {
                continue;
            }
            $cssLinks[] = $header['attributes'];
        }

        return $cssLinks;
    }
}
