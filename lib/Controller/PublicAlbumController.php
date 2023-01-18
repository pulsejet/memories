<?php

namespace OCA\Memories\Controller;

use OCA\Files\Event\LoadSidebar;
use OCA\Memories\Db\TimelineQuery;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Util;

class PublicAlbumController extends Controller
{
    protected $appName;
    protected IEventDispatcher $eventDispatcher;
    protected IInitialState $initialState;
    protected IAppManager $appManager;
    protected IConfig $config;
    protected IDBConnection $connection;

    public function __construct(
        string $appName,
        IEventDispatcher $eventDispatcher,
        IInitialState $initialState,
        IAppManager $appManager,
        IConfig $config,
        IDBConnection $connection
    ) {
        $this->appName = $appName;
        $this->eventDispatcher = $eventDispatcher;
        $this->initialState = $initialState;
        $this->appManager = $appManager;
        $this->config = $config;
        $this->connection = $connection;
    }

    /**
     * @PublicPage
     *
     * @NoCSRFRequired
     */
    public function showShare(string $token): TemplateResponse
    {
        \OC_User::setIncognitoMode(true);

        // Validate token exists
        $timelineQuery = new TimelineQuery($this->connection);
        $album = $timelineQuery->getAlbumByLink($token);
        if (!$album) {
            return new TemplateResponse('core', '404', [], 'guest');
        }

        // Scripts
        Util::addScript($this->appName, 'memories-main');
        $this->eventDispatcher->dispatchTyped(new LoadSidebar());
        PageController::provideCommonInitialState($this->initialState);

        $response = new PublicTemplateResponse($this->appName, 'main');
        $response->setHeaderTitle($album['name']);
        $response->setFooterVisible(false); // wth is that anyway?
        $response->setContentSecurityPolicy(PageController::getCSP());

        return $response;
    }
}
