<?php

declare(strict_types=1);

namespace OCA\Memories\Cron;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Service;
use OCA\Memories\Settings\SystemConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

const MAX_RUN_TIME = 300; // seconds
const INTERVAL = 900; // seconds (don't set this too low)

class IndexJob extends TimedJob
{
    private bool $_hasError = false;

    public function __construct(
        ITimeFactory $time,
        private Service\Index $service,
        private IUserManager $userManager,
        private LoggerInterface $logger,
        private IConfig $config,
    ) {
        parent::__construct($time);

        $this->setInterval(INTERVAL);
    }

    /**
     * Run the background indexing job.
     */
    protected function run(mixed $argument): void
    {
        // Check if indexing is enabled
        if ('0' === SystemConfig::get('memories.index.mode')) {
            return;
        }

        // Store the last run time
        $this->config->setAppValue(Application::APPNAME, 'last_index_job_start', (string) time());
        $this->config->setAppValue(Application::APPNAME, 'last_index_job_duration', (string) 0);

        // Run for a maximum of 5 minutes
        $startTime = microtime(true);
        $this->service->continueCheck = static function () use ($startTime): bool {
            return (microtime(true) - $startTime) < MAX_RUN_TIME;
        };

        // Index with static exiftool process
        // This is sub-optimal: the process may not be required at all.
        try {
            // Start and make sure exiftool is working
            \OCA\Memories\Exif::ensureStaticExiftoolProc();
            Service\BinExt::testExiftool(); // throws

            // Run the indexer
            $this->indexAllUsers();

            // Remove stale index entries
            $this->service->cleanupStale();

            $this->log('Indexing completed successfully', 'success');
        } catch (Service\ProcessClosedException $e) {
            $this->log('Indexing process stopped before completion. Will continue on next run', 'info');
        } catch (\Exception $e) {
            $this->log('Indexing exception: '.$e->getMessage());
        } finally {
            // Close the static exiftool process
            \OCA\Memories\Exif::closeStaticExiftoolProc();
        }

        // Store the last run duration
        $duration = round(microtime(true) - $startTime, 2);
        $this->config->setAppValue(Application::APPNAME, 'last_index_job_duration', (string) $duration);
    }

    /**
     * Index all users.
     *
     * @throws Service\ProcessClosedException if the process was closed before completion
     */
    private function indexAllUsers(): void
    {
        $this->userManager->callForSeenUsers(function ($user) {
            try {
                $this->service->indexUser($user);
            } catch (Service\ProcessClosedException $e) {
                throw $e;
            } catch (\Exception $e) {
                $this->log('Indexing failed for user '.$user->getUID().': '.$e->getMessage());
            } catch (\Throwable $e) {
                $this->log('[BUG] uncaught exception: '.$e->getMessage());
            }
        });
    }

    private function log(string $msg, string $type = 'error'): void
    {
        if ('success' === $type || 'info' === $type) {
            // If this is just an informational message, we log it with level info
            $this->logger->info($msg, ['app' => Application::APPNAME]);
        }

        if ($this->_hasError && 'success' === $type) {
            // Don't overwrite an error with a success
            return;
        }

        $this->config->setAppValue(Application::APPNAME, 'last_index_job_status', $msg);
        $this->config->setAppValue(Application::APPNAME, 'last_index_job_status_type', $type);

        if ('warning' === $type) {
            $this->logger->warning($msg, ['app' => Application::APPNAME]);
        } elseif ('error' === $type) {
            $this->_hasError = true;
            $this->logger->error($msg, ['app' => Application::APPNAME]);
        }
    }
}
