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
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;

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
     * @return JSONResponse an empty JSONResponse with respective http status code
     */
    public function setUserConfig(string $key, string $value): Http\Response
    {
        return Util::guardEx(function () use ($key, $value) {
            // Make sure not running in read-only mode
            if ($this->config->getSystemValue('memories.readonly', false)) {
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
            $version = \OC::$server->get(\OCP\App\IAppManager::class)
                ->getAppInfo('memories')['version']
            ;

            // get user if logged in
            try {
                $uid = Util::getUID();
            } catch (\Exception $e) {
                $uid = null;
            }

            // helper function to get user config values
            $getAppConfig = function ($key, $default) use ($uid) {
                return $this->config->getUserValue($uid, Application::APPNAME, $key, $default);
            };

            $defaultTimelinePath = $this->config->getSystemValue('memories.default_timeline_path', 'EMPTY');

            return new JSONResponse([
                // general stuff
                'version' => $version,
                'vod_disable' => Util::getSystemConfig('memories.vod.disable'),
                'video_default_quality' => Util::getSystemConfig('memories.video_default_quality'),
                'places_gis' => Util::getSystemConfig('memories.gis_type'),

                // enabled apps
                'systemtags_enabled' => Util::tagsIsEnabled(),
                'albums_enabled' => Util::albumsIsEnabled(),
                'recognize_installed' => Util::recognizeIsInstalled(),
                'recognize_enabled' => Util::recognizeIsEnabled(),
                'facerecognition_installed' => Util::facerecognitionIsInstalled(),
                'facerecognition_enabled' => Util::facerecognitionIsEnabled(),
                'preview_generator_enabled' => Util::previewGeneratorIsEnabled(),

                // general settings
                'timeline_path' => $getAppConfig('timelinePath', $defaultTimelinePath),
                'enable_top_memories' => 'true' === $getAppConfig('enableTopMemories', 'true'),

                // viewer settings
                'high_res_cond_default' => Util::getSystemConfig('memories.viewer.high_res_cond_default'),
                'livephoto_autoplay' => 'true' === $getAppConfig('livephotoAutoplay', 'true'),
                'sidebar_filepath' => 'true' === $getAppConfig('sidebarFilepath', false),

                // folder settings
                'folders_path' => $getAppConfig('foldersPath', '/'),
                'show_hidden_folders' => 'true' === $getAppConfig('showHidden', false),
                'sort_folder_month' => 'true' === $getAppConfig('sortFolderMonth', false),

                // album settings
                'sort_album_month' => 'true' === $getAppConfig('sortAlbumMonth', 'true'),
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
    public function describeApi(): JSONResponse
    {
        return Util::guardEx(static function () {
            $appManager = \OC::$server->get(\OCP\App\IAppManager::class);
            $urlGenerator = \OC::$server->get(\OCP\IURLGenerator::class);

            $info = [
                'version' => $appManager->getAppInfo('memories')['version'],
                'baseUrl' => $urlGenerator->linkToRouteAbsolute('memories.Page.main'),
                'loginFlowUrl' => $urlGenerator->linkToRouteAbsolute('core.ClientFlowLoginV2.init'),
            ];

            try {
                $info['uid'] = Util::getUID();
            } catch (\Exception $e) {
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
    public function serviceWorker(): StreamResponse
    {
        $response = new StreamResponse(__DIR__.'/../../js/memories-service-worker.js');
        $response->setHeaders([
            'Content-Type' => 'application/javascript',
            'Service-Worker-Allowed' => '/',
        ]);
        $response->setContentSecurityPolicy(PageController::getCSP());

        return $response;
    }
}
