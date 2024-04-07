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
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;

class OtherController extends GenericApiController
{
    /**
     * @NoAdminRequired
     *
     * update preferences (user setting)
     *
     * @param string key the identifier to change
     * @param string value the value to set
     *
     * @return Http\Response empty JSONResponse with respective http status code
     */
    public function setUserConfig(string $key, string $value): Http\Response
    {
        return Util::guardEx(function () use ($key, $value) {
            // Make sure not running in read-only mode
            if (SystemConfig::get('memories.readonly', false)) {
                throw Exceptions::Forbidden('Cannot change settings in readonly mode');
            }

            $this->config->setUserValue(Util::getUID(), Application::APPNAME, $key, $value);

            return new JSONResponse([], Http::STATUS_OK);
        });
    }

    /**
     * @NoAdminRequired
     *
     * @PublicPage
     */
    public function getUserConfig(): Http\Response
    {
        return Util::guardEx(function () {
            // get memories version
            $version = \OC::$server->get(\OCP\App\IAppManager::class)->getAppVersion('memories');

            // get user if logged in
            try {
                $uid = Util::getUID();
            } catch (\Exception $e) {
                $uid = null;
            }

            // helper function to get user config values
            $getAppConfig = function (string $key, mixed $default) use ($uid): mixed {
                return $this->config->getUserValue($uid, Application::APPNAME, $key, $default);
            };

            return new JSONResponse([
                // general stuff
                'version' => $version,
                'vod_disable' => SystemConfig::get('memories.vod.disable'),
                'video_default_quality' => SystemConfig::get('memories.video_default_quality'),
                'places_gis' => SystemConfig::get('memories.gis_type'),

                // enabled apps
                'systemtags_enabled' => Util::tagsIsEnabled(),
                'albums_enabled' => Util::albumsIsEnabled(),
                'recognize_installed' => Util::recognizeIsInstalled(),
                'recognize_enabled' => Util::recognizeIsEnabled(),
                'facerecognition_installed' => Util::facerecognitionIsInstalled(),
                'facerecognition_enabled' => Util::facerecognitionIsEnabled(),
                'preview_generator_enabled' => Util::previewGeneratorIsEnabled(),

                // general settings
                'timeline_path' => $getAppConfig('timelinePath', SystemConfig::get('memories.timeline.default_path')),
                'enable_top_memories' => 'true' === $getAppConfig('enableTopMemories', 'true'),
                'stack_raw_files' => 'true' === $getAppConfig('stackRawFiles', 'true'),

                // viewer settings
                'high_res_cond_default' => SystemConfig::get('memories.viewer.high_res_cond_default'),
                'livephoto_autoplay' => 'true' === $getAppConfig('livephotoAutoplay', 'true'),
                'sidebar_filepath' => 'true' === $getAppConfig('sidebarFilepath', false),

                // folder settings
                'folders_path' => $getAppConfig('foldersPath', '/'),
                'show_hidden_folders' => 'true' === $getAppConfig('showHidden', false),
                'sort_folder_month' => 'true' === $getAppConfig('sortFolderMonth', false),

                // album settings
                'sort_album_month' => 'true' === $getAppConfig('sortAlbumMonth', 'true'),
                'show_hidden_albums' => 'true' === $getAppConfig('showHiddenAlbums', false),
                'album_list_sort' => $getAppConfig('album_list_sort', 3),
            ], Http::STATUS_OK);
        });
    }

    /**
     * @NoAdminRequired
     *
     * @PublicPage
     *
     * @NoCSRFRequired
     */
    public function describeApi(): Http\Response
    {
        return Util::guardEx(static function () {
            $appManager = \OC::$server->get(\OCP\App\IAppManager::class);
            $urlGenerator = \OC::$server->get(\OCP\IURLGenerator::class);

            $info = [
                'version' => $appManager->getAppVersion('memories'),
                'baseUrl' => $urlGenerator->linkToRouteAbsolute('memories.Page.main'),
                'loginFlowUrl' => $urlGenerator->linkToRouteAbsolute('core.ClientFlowLoginV2.init'),
            ];

            try {
                $info['uid'] = Util::getUID();
            } catch (\Exception) {
                $info['uid'] = null;
            }

            // This is public information
            $res = new JSONResponse($info);
            $res->addHeader('Access-Control-Allow-Origin', '*');

            return $res;
        });
    }

    /**
     * @NoAdminRequired
     *
     * @PublicPage
     *
     * @NoCSRFRequired
     */
    public function static(string $name): Http\Response
    {
        return Util::guardEx(static function () use ($name) {
            switch ($name) {
                case 'service-worker.js':
                    // Disable service worker if server is in debug mode
                    if (!\OC::$server->get(\OCP\IConfig::class)->getSystemValue('memories.sw.enabled', true)) {
                        throw Exceptions::NotFound('Service worker is disabled in global configuration');
                    }

                    // Get relative URL to JS web root of the app
                    $prefix = \OC::$server->get(\OCP\IURLGenerator::class)->linkTo('memories', 'js/memories-main.js');
                    $prefix = preg_replace('/memories-main\.js.*$/', '', $prefix);

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
                    switch (\OC::$server->get(IRequest::class)->getParam('arch')) {
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
            $response->setContentSecurityPolicy(PageController::getCSP());

            return $response;
        });
    }
}
