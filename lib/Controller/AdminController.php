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

class AdminController extends GenericApiController
{
    /**
     * @AdminRequired
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
            $exiftoolNoLocal = Util::getSystemConfig('memories.exiftool_no_local');
            $status['exiftool'] = $this->getExecutableStatus(
                static fn () => BinExt::getExiftoolPBin(),
                static fn ($p) => BinExt::testExiftool(),
                !$exiftoolNoLocal,
                !$exiftoolNoLocal,
            );

            // Check for system perl
            $status['perl'] = $this->getExecutableStatus(exec('which perl'), static fn ($p) => BinExt::testSystemPerl($p));

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

            // Check for PHP Imagick
            $status['imagick'] = class_exists('\Imagick') ? \Imagick::getVersion()['versionString'] : false;

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

            // Check for FFmpeg for preview generation
            $status['ffmpeg_preview'] = $this->getExecutableStatus(
                Util::getSystemConfig('preview_ffmpeg_path', null, true)
                    ?: trim(shell_exec('which ffmpeg') ?: ''),
                static fn ($p) => BinExt::testFFmpeg($p, 'ffmpeg'),
            );

            // Check ffmpeg and ffprobe binaries for transcoding
            $status['ffmpeg'] = $this->getExecutableStatus(
                Util::getSystemConfig('memories.vod.ffmpeg'),
                static fn ($p) => BinExt::testFFmpeg($p, 'ffmpeg'),
            );
            $status['ffprobe'] = $this->getExecutableStatus(
                Util::getSystemConfig('memories.vod.ffprobe'),
                static fn ($p) => BinExt::testFFmpeg($p, 'ffprobe'),
            );

            // Check go-vod binary
            $extGoVod = Util::getSystemConfig('memories.vod.external');
            $status['govod'] = $this->getExecutableStatus(
                static fn () => BinExt::getGoVodBin(),
                static fn ($p) => BinExt::testStartGoVod(),
                !$extGoVod,
                !$extGoVod,
            );

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

        return Util::guardExDirect(static function (Http\IOutput $out) {
            try {
                // Set PHP timeout to infinite
                set_time_limit(0);

                // Send headers for long-running request
                $out->setHeader('Content-Type: text/plain');
                $out->setHeader('X-Accel-Buffering: no');
                $out->setHeader('Cache-Control: no-cache');
                $out->setHeader('Connection: keep-alive');
                $out->setHeader('Content-Length: 0');

                $places = \OC::$server->get(\OCA\Memories\Service\Places::class);
                $datafile = $places->downloadPlanet();
                $places->importPlanet($datafile);
                $places->recalculateAll();

                $out->setOutput("Done.\n");
            } catch (\Exception $e) {
                $out->setOutput('Failed: '.$e->getMessage()."\n");
            }
        });
    }

    /**
     * Get the status of an executable.
     *
     * @param \Closure|string $path             Path to the executable
     * @param ?\Closure       $testFunction     Function to test the executable
     * @param bool            $testIfFile       Test if the path is a file
     * @param bool            $testIfExecutable Test if the path is executable
     */
    private function getExecutableStatus(
        $path,
        ?\Closure $testFunction = null,
        bool $testIfFile = true,
        bool $testIfExecutable = true
    ): string {
        if ($testIfFile) {
            if ($path instanceof \Closure) {
                try {
                    $path = $path();
                } catch (\Exception $e) {
                    return 'test_fail:'.$e->getMessage();
                }
            }

            if (!\is_string($path) || !is_file($path)) {
                return 'not_found';
            }
        }

        if ($testIfExecutable && !is_executable($path)) {
            return 'not_executable';
        }

        if ($testFunction) {
            try {
                return 'test_ok:'.$testFunction($path);
            } catch (\Exception $e) {
                return 'test_fail:'.$e->getMessage();
            }
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
