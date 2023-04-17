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
use OCA\Memories\Service\BinExt;
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
     * @AdminRequired
     *
     * @NoCSRFRequired
     */
    public function getSystemConfig(): Http\Response
    {
        return Util::guardEx(function () {
            $config = [];
            foreach (Util::systemConfigDefaults() as $key => $default) {
                $config[$key] = $this->config->getSystemValue($key, $default);
            }

            return new JSONResponse($config, Http::STATUS_OK);
        });
    }

    /**
     * @AdminRequired
     *
     * @param mixed $value
     */
    public function setSystemConfig(string $key, $value): Http\Response
    {
        return Util::guardEx(function () use ($key, $value) {
            // Make sure not running in read-only mode
            if ($this->config->getSystemValue('memories.readonly', false)) {
                throw Exceptions::Forbidden('Cannot change settings in readonly mode');
            }

            // Assign config with type checking
            Util::setSystemConfig($key, $value);

            // If changing vod settings, kill any running go-vod instances
            if (0 === strpos($key, 'memories.vod.')) {
                try {
                    BinExt::startGoVod();
                } catch (\Exception $e) {
                    error_log('Failed to start go-vod: '.$e->getMessage());
                }
            }

            return new JSONResponse([], Http::STATUS_OK);
        });
    }

    /**
     * @AdminRequired
     *
     * @NoCSRFRequired
     *
     * @UseSession
     */
    public function getSystemStatus(): Http\Response
    {
        return Util::guardEx(function () {
            $config = \OC::$server->get(\OCP\IConfig::class);
            $index = \OC::$server->get(\OCA\Memories\Service\Index::class);

            // Build status array
            $status = [];

            // Check exiftool version
            try {
                $s = $this->getExecutableStatus(BinExt::getExiftoolPBin());
                if ('ok' === $s || Util::getSystemConfig('memories.exiftool_no_local')) {
                    BinExt::testExiftool();
                    $s = 'test_ok';
                }
                $status['exiftool'] = $s;
            } catch (\Exception $e) {
                $status['exiftool'] = 'test_fail:'.$e->getMessage();
            }

            // Check for system perl
            $status['perl'] = $this->getExecutableStatus(exec('which perl'));

            // Check number of indexed files
            $status['indexed_count'] = $index->getIndexedCount();

            // Automatic indexing stats
            $jobStart = $config->getAppValue(Application::APPNAME, 'last_index_job_start', 0);
            $status['last_index_job_start'] = $jobStart ? time() - $jobStart : 0; // Seconds ago
            $status['last_index_job_duration'] = $config->getAppValue(Application::APPNAME, 'last_index_job_duration', 0);
            $status['last_index_job_status'] = $config->getAppValue(Application::APPNAME, 'last_index_job_status', 'Indexing has not been run yet');
            $status['last_index_job_status_type'] = $config->getAppValue(Application::APPNAME, 'last_index_job_status_type', 'warning');

            // Check supported preview mimes
            $status['mimes'] = $index->getPreviewMimes($index->getAllMimes());

            // Check for bad encryption module
            $status['bad_encryption'] = \OCA\Memories\Util::isEncryptionEnabled();

            // Get GIS status
            $places = \OC::$server->get(\OCA\Memories\Service\Places::class);

            try {
                $status['gis_type'] = $places->detectGisType();
                $status['gis_count'] = $places->geomCount();
            } catch (\Exception $e) {
                $status['gis_type'] = $e->getMessage();
            }

            // Check ffmpeg and ffprobe binaries
            $status['ffmpeg'] = $this->getExecutableStatus(Util::getSystemConfig('memories.vod.ffmpeg'));
            $status['ffprobe'] = $this->getExecutableStatus(Util::getSystemConfig('memories.vod.ffprobe'));

            // Check go-vod binary
            try {
                $s = $this->getExecutableStatus(BinExt::getGoVodBin());
                if ('ok' === $s || Util::getSystemConfig('memories.vod.external')) {
                    BinExt::testStartGoVod();
                    $s = 'test_ok';
                }
                $status['govod'] = $s;
            } catch (\Exception $e) {
                $status['govod'] = 'test_fail:'.$e->getMessage();
            }

            // Check for VA-API device
            $devPath = '/dev/dri/renderD128';
            if (!file_exists($devPath)) {
                $status['vaapi_dev'] = 'not_found';
            } elseif (!is_readable($devPath)) {
                $status['vaapi_dev'] = 'not_readable';
            } else {
                $status['vaapi_dev'] = 'ok';
            }

            // Action token
            $status['action_token'] = $this->actionToken(true);

            return new JSONResponse($status, Http::STATUS_OK);
        });
    }

    /**
     * @AdminRequired
     *
     * @UseSession
     */
    public function placesSetup(?string $actiontoken): Http\Response
    {
        if (!$actiontoken || $this->actionToken() !== $actiontoken) {
            return new JSONResponse(['error' => 'Invalid action token. Refresh the memories admin page.'], Http::STATUS_BAD_REQUEST);
        }

        // Reset action token
        $this->actionToken(true);

        try {
            // Set PHP timeout to infinite
            set_time_limit(0);

            // Send headers for long-running request
            header('Content-Type: text/plain');
            header('X-Accel-Buffering: no');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('Content-Length: 0');

            $places = \OC::$server->get(\OCA\Memories\Service\Places::class);
            $datafile = $places->downloadPlanet();
            $places->importPlanet($datafile);
            $places->recalculateAll();

            echo "Done.\n";
        } catch (\Exception $e) {
            echo 'Failed: '.$e->getMessage()."\n";
        }

        exit;
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

    private function getExecutableStatus(string $path): string
    {
        if (!is_file($path)) {
            return 'not_found';
        }

        if (!is_executable($path)) {
            return 'not_executable';
        }

        return 'ok';
    }

    private function actionToken(bool $set = false): string
    {
        $session = \OC::$server->get(\OCP\ISession::class);
        if (!$set) {
            return $session->get('memories_action_token');
        }

        $token = bin2hex(random_bytes(32));
        $session->set('memories_action_token', $token);

        return $token ?? '';
    }
}
