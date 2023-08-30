<?php

namespace OCA\Memories\Controller;

use OCA\Memories\Db\AlbumsQuery;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Template\LinkMenuAction;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IL10N;
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
    protected AlbumsQuery $albumsQuery;
    protected IL10N $l10n;

    public function __construct(
        string $appName,
        IEventDispatcher $eventDispatcher,
        IInitialState $initialState,
        IAppManager $appManager,
        IConfig $config,
        IUserSession $userSession,
        IRootFolder $rootFolder,
        IURLGenerator $urlGenerator,
        AlbumsQuery $albumsQuery,
        IL10N $l10n
    ) {
        $this->appName = $appName;
        $this->eventDispatcher = $eventDispatcher;
        $this->initialState = $initialState;
        $this->appManager = $appManager;
        $this->config = $config;
        $this->userSession = $userSession;
        $this->rootFolder = $rootFolder;
        $this->urlGenerator = $urlGenerator;
        $this->albumsQuery = $albumsQuery;
        $this->l10n = $l10n;
    }

    /**
     * @PublicPage
     *
     * @NoCSRFRequired
     */
    public function showShare(string $token)
    {
        // Validate token exists
        $album = $this->albumsQuery->getAlbumByLink($token);
        if (!$album) {
            return new TemplateResponse('core', '404', [], 'guest');
        }

        // Check if the current user has access to the album
        // Just redirect to the user's page if the user is the owner or a collaborator
        if ($user = $this->userSession->getUser()) {
            $uid = $user->getUID();
            $albumId = (int) $album['album_id'];

            if ($uid === $album['user'] || $this->albumsQuery->userIsCollaborator($uid, $albumId)) {
                $idStr = $album['user'].'/'.$album['name'];
                $url = $this->urlGenerator->linkToRoute('memories.Page.albums', [
                    'id' => $idStr, // id of album
                    'noinit' => 1, // prevent showing first-start page
                ]);

                return new RedirectResponse($url);
            }
        }

        // Browse anonymously if the album is accessed as a link
        \OC_User::setIncognitoMode(true);

        // Add OG metadata
        $this->addOgMetadata($album, $token);

        // Scripts
        Util::addScript($this->appName, 'memories-main');

        // Share info
        $this->initialState->provideInitialState('share_title', $album['name']);

        // Render main template
        $response = new PublicTemplateResponse($this->appName, 'main', PageController::getMainParams());
        $response->setHeaderTitle($album['name']);
        $response->setFooterVisible(false); // wth is that anyway?
        $response->setContentSecurityPolicy(PageController::getCSP());

        // Add download link
        $dlUrl = $this->urlGenerator->linkToRoute('memories.PublicAlbum.download', [
            'token' => $token, // share identification
            'albums' => 1, // identify backend for share
        ]);
        $dlAction = new LinkMenuAction($this->l10n->t('Download'), 'icon-download', $dlUrl);
        $response->setHeaderActions([$dlAction]);

        return $response;
    }

    /**
     * @PublicPage
     *
     * @NoCSRFRequired
     */
    public function download(string $token)
    {
        $album = $this->albumsQuery->getAlbumByLink($token);
        if (!$album) {
            return new TemplateResponse('core', '404', [], 'guest');
        }

        // Get list of files
        $albumId = (int) $album['album_id'];
        $files = $this->albumsQuery->getAlbumPhotos($albumId, null) ?? [];
        $fileIds = array_map(static fn ($file) => (int) $file['file_id'], $files);

        // Get download handle
        $downloadController = \OC::$server->get(\OCA\Memories\Controller\DownloadController::class);
        $handle = $downloadController::createHandle($album['name'], $fileIds);

        // Start download
        return $downloadController->file($handle);
    }

    private function addOgMetadata(array $album, string $token)
    {
        $fileId = (int) $album['last_added_photo'];
        $albumId = (int) $album['album_id'];
        $owner = $this->albumsQuery->hasFile($albumId, $fileId);
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
