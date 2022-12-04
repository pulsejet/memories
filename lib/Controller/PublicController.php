<?php

namespace OCA\Memories\Controller;

use OCA\Files\Event\LoadSidebar;
use OCP\App\IAppManager;
use OCP\AppFramework\AuthPublicShareController;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\Util;

class PublicController extends AuthPublicShareController
{
    protected $appName;
    protected IEventDispatcher $eventDispatcher;
    protected IInitialState $initialState;
    protected IShareManager $shareManager;
    protected IUserManager $userManager;
    protected IAppManager $appManager;
    protected IConfig $config;

    protected IShare $share;

    public function __construct(
        string $AppName,
        IRequest $request,
        ISession $session,
        IURLGenerator $urlGenerator,
        IEventDispatcher $eventDispatcher,
        IInitialState $initialState,
        IShareManager $shareManager,
        IUserManager $userManager,
        IAppManager $appManager,
        IConfig $config
    ) {
        parent::__construct($AppName, $request, $session, $urlGenerator);
        $this->eventDispatcher = $eventDispatcher;
        $this->initialState = $initialState;
        $this->shareManager = $shareManager;
        $this->userManager = $userManager;
        $this->appManager = $appManager;
        $this->config = $config;
    }

    /**
     * @PublicPage
     *
     * @NoCSRFRequired
     *
     * Show the authentication page
     * The form has to submit to the authenticate method route
     */
    public function showAuthenticate(): TemplateResponse
    {
        $templateParameters = ['share' => $this->share];

        return new TemplateResponse('core', 'publicshareauth', $templateParameters, 'guest');
    }

    public function isValidToken(): bool
    {
        try {
            $this->share = $this->shareManager->getShareByToken($this->getToken());

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @PublicPage
     *
     * @NoCSRFRequired
     */
    public function showShare(): TemplateResponse
    {
        \OC_User::setIncognitoMode(true);

        // Check whether share exists
        try {
            $share = $this->shareManager->getShareByToken($this->getToken());
        } catch (\Exception $e) {
            throw new NotFoundException();
        }

        if (!self::validateShare($share)) {
            throw new NotFoundException();
        }

        // Scripts
        Util::addScript($this->appName, 'memories-main');
        $this->eventDispatcher->dispatchTyped(new LoadSidebar());

        // App version
        $this->initialState->provideInitialState('version', $this->appManager->getAppInfo('memories')['version']);

        // Video configuration
        $this->initialState->provideInitialState('notranscode', $this->config->getSystemValue('memories.no_transcode', 'UNSET'));

        // Share info
        $this->initialState->provideInitialState('no_download', $share->getHideDownload());

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

        $response = new PublicTemplateResponse($this->appName, 'main');
        $response->setContentSecurityPolicy($policy);

        return $response;
    }

    /**
     * Validate the permissions of the share.
     */
    public static function validateShare(?IShare $share): bool
    {
        if (null === $share) {
            return false;
        }

        // Get user manager
        $userManager = \OC::$server->get(IUserManager::class);

        // Check if share read is allowed
        if (!($share->getPermissions() & \OCP\Constants::PERMISSION_READ)) {
            return false;
        }

        // If the owner is disabled no access to the linke is granted
        $owner = $userManager->get($share->getShareOwner());
        if (null === $owner || !$owner->isEnabled()) {
            return false;
        }

        // If the initiator of the share is disabled no access is granted
        $initiator = $userManager->get($share->getSharedBy());
        if (null === $initiator || !$initiator->isEnabled()) {
            return false;
        }

        return $share->getNode()->isReadable() && $share->getNode()->isShareable();
    }

    protected function showAuthFailed(): TemplateResponse
    {
        $templateParameters = ['share' => $this->share, 'wrongpw' => true];

        return new TemplateResponse('core', 'publicshareauth', $templateParameters, 'guest');
    }

    protected function verifyPassword(string $password): bool
    {
        return $this->shareManager->checkPassword($this->share, $password);
    }

    protected function getPasswordHash(): string
    {
        return $this->share->getPassword();
    }

    protected function isPasswordProtected(): bool
    {
        return null !== $this->share->getPassword();
    }
}
