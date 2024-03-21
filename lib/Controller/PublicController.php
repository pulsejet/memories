<?php

declare(strict_types=1);

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
    /** @psalm-suppress PropertyNotSetInConstructor */
    protected IShare $share;

    public function __construct(
        IRequest $request,
        ISession $session,
        IURLGenerator $urlGenerator,
        protected IEventDispatcher $eventDispatcher,
        protected IInitialState $initialState,
        protected IUserSession $userSession,
        protected IRootFolder $rootFolder,
        protected IShareManager $shareManager,
        protected IConfig $config,
        protected TimelineQuery $tq,
    ) {
        parent::__construct(Application::APPNAME, $request, $session, $urlGenerator);
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
        } catch (\Exception) {
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
        } catch (\Exception) {
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
        \OCP\Util::addScript(Application::APPNAME, 'memories-main');

        // Get share node
        $node = $share->getNode();

        // Share info
        $this->initialState->provideInitialState('no_download', $share->getHideDownload());
        $this->initialState->provideInitialState('share_title', $node->getName());

        if ($node instanceof \OCP\Files\File) {
            $this->initialState->provideInitialState('single_item', $this->getSingleItemInitialState($node));
            $this->initialState->provideInitialState('share_type', 'file');
        } elseif ($node instanceof \OCP\Files\Folder) {
            $this->initialState->provideInitialState('share_type', 'folder');
        } else {
            throw new NotFoundException();
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
        // TODO: return type has changed to ?string with 29
        // Change this when dropping support for 28
        return $this->share->getPassword() ?? '';
    }

    protected function isPasswordProtected(): bool
    {
        /** @psalm-suppress RedundantConditionGivenDocblockType */
        return null !== $this->share->getPassword();
    }

    protected function redirectIfOwned(IShare $share): void
    {
        $user = $this->userSession->getUser();
        if (!$user) {
            return;
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
                return;
            }
            $node = $nodes[0];
        } catch (NotFoundException $e) {
            return;
        }

        // Check if node is a folder
        if (!$node instanceof \OCP\Files\Folder) {
            return;
        }

        // Remove user folder path from start of node path
        $relPath = substr($node->getPath(), \strlen($userFolder->getPath()));

        // Get the user's folders path
        $foldersPath = $this->config->getUserValue($user->getUID(), Application::APPNAME, 'foldersPath', null) ?: '/';

        // Sanitize folders path ensuring leading and trailing slashes
        $foldersPath = Util::sanitizePath('/'.$foldersPath.'/');

        // Check if relPath starts with foldersPath
        if (empty($foldersPath) || !str_starts_with($relPath, $foldersPath)) {
            return;
        }

        /** @var string $foldersPath */
        // Remove foldersPath from start of relPath
        $relPath = substr($relPath, \strlen($foldersPath));

        // Redirect to the local path
        $url = $this->urlGenerator->linkToRouteAbsolute('memories.Page.folder', [
            'path' => $relPath, // path to folder
            'noinit' => 1, // prevent showing first-start page
        ]);

        // Cannot send a redirect response here because the return
        // type is a template response for the base class
        header('HTTP/1.1 302 Found');
        header('Location: '.$url);

        exit; // no other way to do this due to typing of super class
    }

    /**
     * Get initial state of single item.
     *
     * @throws NotFoundException if file not found in index
     */
    private function getSingleItemInitialState(\OCP\Files\File $file): array
    {
        return $this->tq->getSingleItem($file->getId())
            ?? throw new NotFoundException();
    }
}
