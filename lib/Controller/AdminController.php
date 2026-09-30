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
use OCA\Memories\Db\TimelineWrite;
use OCA\Memories\Exceptions;
use OCA\Memories\Service\BinExt;
use OCA\Memories\Service\Index;
use OCA\Memories\Service\MIME;
use OCA\Memories\Service\Places;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\ISession;

final class AdminController extends ApiController
{
    public function __construct(
        IRequest $request,
        protected IAppConfig $appConfig,
        protected Index $index,
        protected MIME $mime,
        protected TimelineWrite $tw,
        protected IDBConnection $connection,
        protected Places $places,
        protected ISession $session,
        protected SystemConfig $systemConfig,
        protected BinExt $binExt,
        protected Util $util,
    ) {
        parent::__construct(Application::APPNAME, $request);
    }

    /**
     * @AdminRequired
     */
    public function getSystemConfig(): Http\Response
    {
        return $this->util->guardEx(function () {
            $config = [];
            foreach (SystemConfig::DEFAULTS as $key => $default) {
                $config[$key] = $this->systemConfig->get($key);
            }

            // Convert array types from map
            $config['enabledPreviewProviders'] = array_values($config['enabledPreviewProviders']);

            return new JSONResponse($config, Http::STATUS_OK);
        });
    }

    /**
     * @AdminRequired
     */
    public function setSystemConfig(string $key, mixed $value): Http\Response
    {
        return $this->util->guardEx(function () use ($key, $value) {
            // Make sure not running in read-only mode
            if ($this->systemConfig->get('memories.readonly')) {
                throw Exceptions::Forbidden('Cannot change settings in readonly mode');
            }

            // Assign config with type checking
            $this->systemConfig->set($key, $value);

            // Kill go-vod if changing startup config settings.
            if (\in_array($key, [
                'memories.vod.bind',
                'memories.vod.nc_url',
                'memories.vod.connect',
                'memories.vod.path',
                'memories.vod.tempdir',
                'memories.vod.cachedir',
                'memories.vod.ffmpeg',
                'memories.vod.ffprobe',
                'memories.vod.external',
                'memories.vod.disable',
            ], true)) {
                try {
                    $this->binExt->pkill('go-vod');
                    $this->binExt->ensureGoVod();
                } catch (\Exception $e) {
                    error_log('Failed to start go-vod: '.$e->getMessage());
                }
            }

            return new JSONResponse([], Http::STATUS_OK);
        });
    }

    /**
     * @AdminRequired
     */
    #[UseSession]
    public function getSystemStatus(): Http\Response
    {
        return $this->util->guardEx(function () {
            // Build status array
            $status = [];

            // Check exiftool version
            $exiftoolNoLocal = $this->systemConfig->get('memories.exiftool_no_local');
            $status['exiftool'] = $this->getExecutableStatus(
                fn () => $this->binExt->getEPerlBin(),
                fn () => $this->binExt->testExiftool(),
                !$exiftoolNoLocal,
                !$exiftoolNoLocal,
            );

            // Check for system perl
            $status['perl'] = $this->getExecutableStatus(
                trim(Util::execSafe(['which', 'perl'], 3000) ?: '/bin/perl'),
                fn (string $p) => $this->binExt->testSystemPerl($p),
            );

            // Check number of indexed files
            $status['indexed_count'] = $this->index->getIndexedCount();
            $status['failure_count'] = $this->tw->countFailures();

            // Automatic indexing stats
            $jobStart = (int) $this->appConfig->getValueString(Application::APPNAME, 'last_index_job_start', (string) 0);
            $status['last_index_job_start'] = $jobStart ? time() - $jobStart : 0; // Seconds ago
            $status['last_index_job_duration'] = (float) $this->appConfig->getValueString(Application::APPNAME, 'last_index_job_duration', (string) 0);
            $status['last_index_job_status'] = $this->appConfig->getValueString(Application::APPNAME, 'last_index_job_status', 'Indexing has not been run yet');
            $status['last_index_job_status_type'] = $this->appConfig->getValueString(Application::APPNAME, 'last_index_job_status_type', 'warning');

            // Check supported preview mimes
            $status['mimes'] = $this->mime->getPreviewMimes($this->mime->getAllMimes());

            // Check for PHP Imagick
            $status['imagick'] = class_exists('\Imagick') ? \Imagick::getVersion()['versionString'] : false;

            // Check for bad encryption module
            $status['bad_encryption'] = $this->systemConfig->isEncryptionEnabled();

            // Check database platform and parameters
            try {
                $provider = $this->connection->getDatabaseProvider(true);

                // SQLite is not recommended for performance.
                $status['db_is_sqlite'] = \OCP\IDBConnection::PLATFORM_SQLITE === $provider;

                // Check InnoDB buffer pool size for MySQL/MariaDB
                if (\OCP\IDBConnection::PLATFORM_MYSQL === $provider
                 || \OCP\IDBConnection::PLATFORM_MARIADB === $provider) {
                    $status['innodb_buffer_pool_size'] = (int) $this->connection->executeQuery('SELECT @@innodb_buffer_pool_size')->fetchOne();
                }
            } catch (\Exception $e) {
                $status['innodb_buffer_pool_size'] = 0;
            }

            try {
                $status['gis_type'] = $this->places->detectGisType();
                $status['gis_count'] = $this->places->geomCount();
            } catch (\Exception $e) {
                $status['gis_type'] = $e->getMessage();
            }

            // Check for FFmpeg for preview generation
            $status['ffmpeg_preview'] = $this->getExecutableStatus(
                $this->systemConfig->get('preview_ffmpeg_path')
                    ?: trim(Util::execSafe(['which', 'ffmpeg'], 3000) ?: ''),
                fn ($p) => $this->binExt->testFFmpeg($p, 'ffmpeg'),
            );

            // Check ffmpeg and ffprobe binaries for transcoding
            $status['ffmpeg'] = $this->getExecutableStatus(
                $this->systemConfig->get('memories.vod.ffmpeg'),
                fn ($p) => $this->binExt->testFFmpeg($p, 'ffmpeg'),
            );
            $status['ffprobe'] = $this->getExecutableStatus(
                $this->systemConfig->get('memories.vod.ffprobe'),
                fn ($p) => $this->binExt->testFFmpeg($p, 'ffprobe'),
            );

            // Check go-vod binary
            $extGoVod = $this->systemConfig->get('memories.vod.external');
            $status['govod'] = $this->getExecutableStatus(
                fn () => $this->binExt->getGoVodBin(),
                fn ($p) => $this->binExt->testGoVodBin($p),
                !$extGoVod,
                !$extGoVod,
            );

            // Check each go-vod server separately
            $govods = [];
            foreach ($this->binExt->getGoVodServers() as $server) {
                try {
                    $result = $this->binExt->testGoVod($server);
                    $govods[] = [
                        'server' => $server,
                        'healthy' => true,
                        'detail' => $result['version'],
                        'latencyMs' => $result['latencyMs'],
                    ];
                } catch (\Exception $e) {
                    $govods[] = [
                        'server' => $server,
                        'healthy' => false,
                        'detail' => $e->getMessage(),
                    ];
                }
            }
            $status['govod_servers'] = $govods;

            // Check for VA-API device
            $devPath = $this->systemConfig->get('memories.vod.vaapi.device');
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
     */
    #[NoCSRFRequired]
    public function getFailureLogs(): Http\Response
    {
        return $this->util->guardExDirect(function (Http\IOutput $out) {
            $out->setHeader('Content-Type: text/plain');
            $out->setHeader('X-Accel-Buffering: no');
            $out->setHeader('Cache-Control: no-cache');

            foreach ($this->tw->listFailures() as $log) {
                $fileid = str_pad((string) $log['fileid'], 12, ' ', STR_PAD_RIGHT); // size
                $mtime = $log['mtime'];
                $reason = $log['reason'];

                $out->setOutput("{$fileid}[{$mtime}]\t{$reason}\n");
            }
        });
    }

    /**
     * @AdminRequired
     */
    #[UseSession]
    public function placesSetup(?string $actiontoken): Http\Response
    {
        if (!$actiontoken || $this->actionToken() !== $actiontoken) {
            return new JSONResponse(['error' => 'Invalid action token. Refresh the memories admin page.'], Http::STATUS_BAD_REQUEST);
        }

        // Reset action token
        $this->actionToken(true);

        return $this->util->guardExDirect(function (Http\IOutput $out) {
            try {
                // Set PHP timeout to infinite
                set_time_limit(0);

                // Send headers for long-running request
                $out->setHeader('Content-Type: text/plain');
                $out->setHeader('X-Accel-Buffering: no');
                $out->setHeader('Cache-Control: no-cache');
                $out->setHeader('Connection: keep-alive');
                $out->setHeader('Content-Length: 0');

                $this->places->downloadImportPlanet();
                $this->places->recalculateAll();

                $out->setOutput("Places set up successfully.\n");
            } catch (\Exception $e) {
                $out->setOutput('Failed: '.$e->getMessage()."\n");
            }
        });
    }

    /**
     * Get the status of an executable.
     *
     * @param (\Closure():string)|string     $path             Path to the executable
     * @param null|(\Closure(string):string) $testFunction     Function to test the executable
     * @param bool                           $testIfFile       Test if the path is a file
     * @param bool                           $testIfExecutable Test if the path is executable
     */
    private function getExecutableStatus(
        \Closure|string $path,
        ?\Closure $testFunction = null,
        bool $testIfFile = true,
        bool $testIfExecutable = true,
    ): string {
        if ($path instanceof \Closure) {
            try {
                $path = $path();
            } catch (\Exception $e) {
                return 'test_fail:'.$e->getMessage();
            }
        }

        if ($testIfFile && !is_file($path)) {
            return 'not_found';
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
        if (!$set) {
            return $this->session->get('memories_action_token');
        }

        $token = bin2hex(random_bytes(32));
        $this->session->set('memories_action_token', $token);

        return $token;
    }
}
