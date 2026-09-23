<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Varun Patil <radialapps@gmail.com>
 * @author Varun Patil <radialapps@gmail.com>
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\Controller;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Exceptions;
use OCA\Memories\Service\Lens;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\App\IAppManager;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Config\IUserConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\L10N\IFactory as L10NFactory;

final class OtherController extends ApiController
{
    public function __construct(
        IRequest $request,
        protected IUserConfig $userConfig,
        protected IAppManager $appManager,
        protected L10NFactory $l10nFactory,
        protected IURLGenerator $urlGenerator,
        protected IConfig $config,
        protected SystemConfig $systemConfig,
        protected Lens $lens,
        protected Util $util,
    ) {
        parent::__construct(Application::APPNAME, $request);
    }

    /**
     * update preferences (user setting).
     *
     * @param string key the identifier to change
     * @param string value the value to set
     *
     * @return Http\Response empty JSONResponse with respective http status code
     */
    #[NoAdminRequired]
    public function setUserConfig(string $key, string $value): Http\Response
    {
        return $this->util->guardEx(function () use ($key, $value) {
            // Make sure not running in read-only mode
            if ($this->systemConfig->get('memories.readonly', false)) {
                throw Exceptions::Forbidden('Cannot change settings in readonly mode');
            }

            $this->userConfig->setValueString($this->util->getUID(), Application::APPNAME, $key, $value);

            return new JSONResponse([], Http::STATUS_OK);
        });
    }

    #[NoAdminRequired]
    #[PublicPage]
    public function getUserConfig(): Http\Response
    {
        return $this->util->guardEx(function () {
            // get memories version
            $version = $this->appManager->getAppVersion('memories');

            // get user if logged in
            try {
                $uid = $this->util->getUID();
            } catch (\Exception) {
                $uid = null;
            }

            // helper function to get user config values
            $getAppConfig = function (string $key, string $default) use ($uid): string {
                return $uid ? $this->userConfig->getValueString($uid, Application::APPNAME, $key, $default) : $default;
            };

            // user language and locale for native clients
            $language = $this->l10nFactory->findLanguage();
            $locale = $this->l10nFactory->findLocale($language);

            // available map tile servers and the user's selected server URL
            $mapTileServers = $this->systemConfig->get('memories.map.tile_servers');
            $mapTileServerDefault = $mapTileServers[0]['url'] ?? '';
            $mapTileServerUrl = $uid ? $this->userConfig->getValueString($uid, Application::APPNAME, 'mapTileServerUrl', $mapTileServerDefault) : $mapTileServerDefault;
            if (!\in_array($mapTileServerUrl, array_column($mapTileServers, 'url'), true)) {
                $mapTileServerUrl = $mapTileServerDefault;
            }

            return new JSONResponse([
                // general stuff
                'version' => $version,
                'vod_disable' => $this->systemConfig->get('memories.vod.disable'),
                'video_default_quality' => $this->systemConfig->get('memories.video_default_quality'),
                'places_gis' => $this->systemConfig->get('memories.gis_type'),
                'places_search_url' => $this->systemConfig->get('memories.places.search.url'),
                'map_tile_servers' => $mapTileServers,
                'map_tile_server_url' => $mapTileServerUrl,
                'language' => $language,
                'locale' => $locale,

                // enabled apps
                'systemtags_enabled' => $this->systemConfig->tagsIsEnabled(),
                'albums_enabled' => $this->systemConfig->albumsIsEnabled(),
                'recognize_installed' => $this->systemConfig->recognizeIsInstalled(),
                'recognize_enabled' => $this->systemConfig->recognizeIsEnabled(),
                'facerecognition_installed' => $this->systemConfig->facerecognitionIsInstalled(),
                'facerecognition_enabled' => $this->systemConfig->facerecognitionIsEnabled(),
                'lens_enabled' => '' !== trim($this->lens->daemonUrl()),
                'preview_generator_enabled' => $this->systemConfig->previewGeneratorIsEnabled(),

                // general settings
                'timeline_path' => $getAppConfig('timelinePath', $this->systemConfig->get('memories.timeline.default_path')),
                'enable_top_memories' => 'true' === $getAppConfig('enableTopMemories', 'true'),
                'stack_raw_files' => 'true' === $getAppConfig('stackRawFiles', 'true'),
                'dedup_identical' => 'true' === $getAppConfig('dedupIdentical', 'false'),
                'show_owner_name_timeline' => 'true' === $getAppConfig('showOwnerNameTimeline', 'false'),

                // viewer settings
                'high_res_cond_default' => $this->systemConfig->get('memories.viewer.high_res_cond_default'),
                'livephoto_autoplay' => 'true' === $getAppConfig('livephotoAutoplay', 'false'),
                'livephoto_loop' => 'true' === $getAppConfig('livephotoLoop', 'false'),
                'video_loop' => 'true' === $getAppConfig('videoLoop', 'false'),
                'sidebar_filepath' => 'true' === $getAppConfig('sidebarFilepath', 'false'),
                'slideshow_duration' => (int) $getAppConfig('slideshowDuration', '5'),

                // on this day settings
                'onthisday_day_range' => (int) $getAppConfig('onthisdayDayRange', '3'),
                'onthisday_photos_per_year' => (int) $getAppConfig('onthisdayPhotosPerYear', '10'),

                // folder settings
                'folders_path' => $getAppConfig('foldersPath', '/'),
                'show_hidden_folders' => 'true' === $getAppConfig('showHidden', 'false'),
                'sort_folder_month' => 'true' === $getAppConfig('sortFolderMonth', 'false'),

                // album settings
                'sort_album_month' => 'true' === $getAppConfig('sortAlbumMonth', 'true'),
                'show_hidden_albums' => 'true' === $getAppConfig('showHiddenAlbums', 'false'),
                'album_list_sort' => (int) $getAppConfig('album_list_sort', '3'),
            ], Http::STATUS_OK);
        });
    }

    #[NoAdminRequired]
    #[PublicPage]
    #[NoCSRFRequired]
    public function describeApi(PageController $pageController): Http\Response
    {
        return $this->util->guardEx(function () use ($pageController) {
            $info = [
                'version' => $this->appManager->getAppVersion('memories'),
                'baseUrl' => $this->urlGenerator->linkToRouteAbsolute('memories.Page.main'),
                'loginFlowUrl' => $this->urlGenerator->linkToRouteAbsolute('core.ClientFlowLoginV2.init'),
            ];

            try {
                $info['uid'] = $this->util->getUID();
            } catch (\Exception) {
                $info['uid'] = null;
            }

            // Static file manifests
            if ('1' === $this->request->getParam('manifest')) {
                $manifest = @file_get_contents(__DIR__.'/../../js/memories-manifest.json');
                $info['jsManifest'] = false !== $manifest ? base64_encode($manifest) : null;
                $manifestSig = @file_get_contents(__DIR__.'/../../js/memories-manifest.sig.json');
                $info['jsManifestSig'] = false !== $manifestSig ? base64_encode($manifestSig) : null;
                $info['cssManifest'] = $pageController->getLinkHeaders();
            }

            // This is public information
            $res = new JSONResponse($info);
            $res->addHeader('Access-Control-Allow-Origin', '*');

            return $res;
        });
    }

    #[NoAdminRequired]
    #[PublicPage]
    #[NoCSRFRequired]
    public function static(string $name): Http\Response
    {
        return $this->util->guardEx(function () use ($name) {
            switch ($name) {
                case 'service-worker.js':
                    // Disable service worker if server is in debug mode
                    if (!$this->config->getSystemValue('memories.sw.enabled', true)) {
                        throw Exceptions::NotFound('Service worker is disabled in global configuration');
                    }

                    // Get relative URL to JS web root of the app
                    $prefix = $this->urlGenerator->linkTo('memories', 'js/memories-main.js');
                    $prefix = preg_replace('/memories-main\.js.*$/', '', $prefix) ?? $prefix;

                    // Make sure prefix starts and ends with a slash
                    $prefix = '/'.ltrim($prefix, '/');
                    $prefix = rtrim($prefix, '/').'/';

                    // Replace relative URLs to have correct prefix
                    $sw = file_get_contents(__DIR__.'/../../js/memories-service-worker.js');
                    $sw = str_replace('/apps/memories/js/', $prefix, $sw);

                    // Return processed service worker
                    $response = (new DataDisplayResponse($sw))->setHeaders([
                        'Content-Type' => 'application/javascript',
                        'Service-Worker-Allowed' => '/',
                    ]);

                    break;

                case 'go-vod':
                    switch ($this->request->getParam('arch')) {
                        case 'x86_64':
                        case 'amd64':
                            return new StreamResponse(__DIR__.'/../../bin-ext/go-vod-amd64');

                        case 'aarch64':
                        case 'arm64':
                            return new StreamResponse(__DIR__.'/../../bin-ext/go-vod-aarch64');
                    }

                    // no break
                default:
                    throw Exceptions::NotFound("File not found: {$name}");
            }

            /** @var Http\Response $response */
            $response->setContentSecurityPolicy($this->systemConfig->getCSP());

            return $response;
        });
    }
}
