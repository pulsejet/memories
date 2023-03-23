<?php

namespace OCA\Memories\Controller;

use OCA\Memories\Db\TimelineQuery;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

class PublicAlbumController extends Controller
{
    protected $appName;
    protected IEventDispatcher $eventDispatcher;
    protected IInitialState $initialState;
    protected IAppManager $appManager;
    protected IConfig $config;
    protected IUserSession $userSession;
    protected IRootFolder $rootFolder;
    protected IURLGenerator $urlGenerator;
    protected TimelineQuery $timelineQuery;

    public function __construct(
        string $appName,
        IEventDispatcher $eventDispatcher,
        IInitialState $initialState,
        IAppManager $appManager,
        IConfig $config,
        IUserSession $userSession,
        IRootFolder $rootFolder,
        IURLGenerator $urlGenerator,
        TimelineQuery $timelineQuery
    ) {
        $this->appName = $appName;
        $this->eventDispatcher = $eventDispatcher;
        $this->initialState = $initialState;
        $this->appManager = $appManager;
        $this->config = $config;
        $this->userSession = $userSession;
        $this->rootFolder = $rootFolder;
        $this->urlGenerator = $urlGenerator;
        $this->timelineQuery = $timelineQuery;
    }

    /**
     * @PublicPage
     *
     * @NoCSRFRequired
     */
    public function showShare(string $token)
    {
        // Validate token exists
        $album = $this->timelineQuery->getAlbumByLink($token);
        if (!$album) {
            return new TemplateResponse('core', '404', [], 'guest');
        }

        // Check if the current user has access to the album
        // Just redirect to the user's page if the user is the owner or a collaborator
        if ($user = $this->userSession->getUser()) {
            $uid = $user->getUID();
            $albumId = (int) $album['album_id'];

            if ($uid === $album['user'] || $this->timelineQuery->userIsAlbumCollaborator($uid, $albumId)) {
                $idStr = $album['user'].'/'.$album['name'];
                $url = $this->urlGenerator->linkToRoute('memories.Page.albums', ['id' => $idStr]);

                return new RedirectResponse($url);
            }
        }

        // Browse anonymously if the album is accessed as a link
        \OC_User::setIncognitoMode(true);

        // Add OG metadata
        $this->addOgMetadata($album, $token);

        // Scripts
        Util::addScript($this->appName, 'memories-main');
        PageController::provideCommonInitialState($this->initialState);

        $response = new PublicTemplateResponse($this->appName, 'main');
        $response->setHeaderTitle($album['name']);
        $response->setFooterVisible(false); // wth is that anyway?
        $response->setContentSecurityPolicy(PageController::getCSP());

        return $response;
    }

    private function addOgMetadata(array $album, string $token)
    {
        $fileId = (int) $album['last_added_photo'];
        $albumId = (int) $album['album_id'];
        $owner = $this->timelineQuery->albumHasFile($albumId, $fileId);
        if (!$owner) {
            return;
        }

        $nodes = $this->rootFolder->getUserFolder($owner)->getById($fileId);
        if (0 === \count($nodes)) {
            return;
        }
        $node = $nodes[0];

        $params = ['token' => $token];
        $url = $this->urlGenerator->linkToRouteAbsolute('memories.PublicAlbum.showShare', $params);
        \OCA\Memories\Util::addOGMetadata($node, $album['name'], $url, array_merge($params, ['album' => true]));
    }
}
