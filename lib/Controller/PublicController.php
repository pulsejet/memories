<?php

namespace OCA\Memories\Controller;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Db\FsManager;
use OCA\Memories\Db\TimelineQuery;
use OCA\Memories\Util;
use OCP\AppFramework\AuthPublicShareController;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

class PublicController extends AuthPublicShareController
{
    protected $appName;
    protected IEventDispatcher $eventDispatcher;
    protected IInitialState $initialState;
    protected IUserSession $userSession;
    protected IRootFolder $rootFolder;
    protected IShareManager $shareManager;
    protected IConfig $config;
    protected TimelineQuery $timelineQuery;

    protected IShare $share;

    public function __construct(
        string $AppName,
        IRequest $request,
        ISession $session,
        IURLGenerator $urlGenerator,
        IEventDispatcher $eventDispatcher,
        IInitialState $initialState,
        IUserSession $userSession,
        IRootFolder $rootFolder,
        IShareManager $shareManager,
        IConfig $config,
        TimelineQuery $timelineQuery
    ) {
        parent::__construct($AppName, $request, $session, $urlGenerator);
        $this->eventDispatcher = $eventDispatcher;
        $this->initialState = $initialState;
        $this->userSession = $userSession;
        $this->rootFolder = $rootFolder;
        $this->shareManager = $shareManager;
        $this->config = $config;
        $this->timelineQuery = $timelineQuery;
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
        $this->redirectIfOwned($this->share);

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
        // Check whether share exists
        try {
            $share = $this->shareManager->getShareByToken($this->getToken());
        } catch (\Exception $e) {
            throw new NotFoundException();
        }

        if (!FsManager::validateShare($share)) {
            throw new NotFoundException();
        }

        // Redirect to main app if user owns this share
        $this->redirectIfOwned($share);

        // Set incognito mode
        \OC_User::setIncognitoMode(true);

        // Scripts
        \OCP\Util::addScript($this->appName, 'memories-main');

        // Share info
        $this->initialState->provideInitialState('no_download', $share->getHideDownload());

        // Share file id only if not a folder
        $node = $share->getNode();
        if ($node instanceof \OCP\Files\File) {
            $this->initialState->provideInitialState('single_item', $this->getSingleItemInitialState($node));
        }

        // Add OG metadata
        $params = ['token' => $this->getToken()];
        $url = $this->urlGenerator->linkToRouteAbsolute('memories.Public.showShare', $params);
        Util::addOgMetadata($node, $node->getName(), $url, $params);

        // Render the template
        $response = new PublicTemplateResponse($this->appName, 'main', PageController::getMainParams());
        $response->setHeaderTitle($node->getName());
        $response->setFooterVisible(false); // wth is that anyway?
        $response->setContentSecurityPolicy(PageController::getCSP());
        $response->cacheFor(0);

        return $response;
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

    protected function redirectIfOwned(IShare $share)
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return null;
        }

        /** @var \OCP\Files\Node */
        $node = null;

        /** @var \OCP\Files\Folder */
        $userFolder = null;

        // Check if the user has this folder in their root
        try {
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            $nodes = $userFolder->getById($share->getNodeId());
            if (0 === \count($nodes)) {
                return null;
            }
            $node = $nodes[0];
        } catch (NotFoundException $e) {
            return null;
        }

        // Check if node is a folder
        if (!$node instanceof \OCP\Files\Folder) {
            return null;
        }

        // Remove user folder path from start of node path
        $relPath = substr($node->getPath(), \strlen($userFolder->getPath()));

        // Get the user's folders path
        $foldersPath = $this->config->getUserValue($user->getUID(), Application::APPNAME, 'foldersPath', '');
        $foldersPath = $foldersPath ?: '/';
        $foldersPath = Util::sanitizePath($foldersPath);

        // Check if relPath starts with foldersPath
        if (0 !== strpos($relPath, $foldersPath)) {
            return null;
        }

        // Remove foldersPath from start of relPath
        $relPath = substr($relPath, \strlen($foldersPath));

        // Redirect to the local path
        $url = $this->urlGenerator->linkToRouteAbsolute('memories.Page.folder', ['path' => $relPath]);

        // Cannot send a redirect response here because the return
        // type is a template response for the base class
        header('HTTP/1.1 302 Found');
        header('Location: '.$url);

        exit; // no other way to do this due to typing of super class
    }

    /** Get initial state of single item */
    private function getSingleItemInitialState(\OCP\Files\File $file): array
    {
        return $this->timelineQuery->getSingleItem($file->getId());
    }
}
