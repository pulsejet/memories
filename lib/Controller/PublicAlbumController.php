<?php

declare(strict_types=1);

namespace OCA\Memories\Controller;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Db\AlbumsQuery;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Template\LinkMenuAction;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

class PublicAlbumController extends Controller
{
    public function __construct(
        IRequest $request,
        protected IEventDispatcher $eventDispatcher,
        protected IInitialState $initialState,
        protected IAppManager $appManager,
        protected IConfig $config,
        protected IUserSession $userSession,
        protected IRootFolder $rootFolder,
        protected IURLGenerator $urlGenerator,
        protected AlbumsQuery $albumsQuery,
        protected IL10N $l10n,
    ) {
        parent::__construct(Application::APPNAME, $request);
    }

    /**
     * @PublicPage
     *
     * @NoCSRFRequired
     */
    public function showShare(string $token): Response
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

        // Get page title
        $title = $album['name'];
        if (str_starts_with($title, '.link-')) {
            $title = $this->l10n->t('Shared Link');
        }

        // Add OG metadata
        $this->addOgMetadata($album, $title, $token);

        // Scripts
        Util::addScript(Application::APPNAME, 'memories-main');

        // Share info
        $this->initialState->provideInitialState('share_title', $title);
        $this->initialState->provideInitialState('share_type', 'album');

        // Render main template
        $response = new PublicTemplateResponse(Application::APPNAME, 'main', PageController::getMainParams());
        $response->setHeaderTitle($title);
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
    public function download(string $token): Response
    {
        $album = $this->albumsQuery->getAlbumByLink($token);
        if (!$album) {
            return new TemplateResponse('core', '404', [], 'guest');
        }

        // Get list of files
        $albumId = (int) $album['album_id'];
        $files = $this->albumsQuery->getAlbumPhotos($albumId, null, null);
        $fileIds = array_map(static fn ($file) => (int) $file['file_id'], $files);

        // Get download handle
        $downloadController = \OC::$server->get(\OCA\Memories\Controller\DownloadController::class);
        $handle = $downloadController::createHandle($album['name'], $fileIds);

        // Start download
        return $downloadController->file($handle);
    }

    private function addOgMetadata(array $album, string $title, string $token): void
    {
        $fileId = (int) ($album['cover_owner'] ?? $album['last_added_photo']);
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
        \OCA\Memories\Util::addOGMetadata($node, $title, $url, array_merge($params, ['albums' => true]));
    }
}
