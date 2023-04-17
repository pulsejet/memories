<?php

namespace OCA\Memories\Cron;

use OCA\Memories\Service;
use OCA\Memories\Util;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

const MAX_RUN_TIME = 10; // seconds
const INTERVAL = 600; // seconds (don't set this too low)

class IndexJob extends TimedJob
{
    private Service\Index $service;
    private IUserManager $userManager;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        Service\Index $service,
        IUserManager $userManager,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->service = $service;
        $this->userManager = $userManager;
        $this->logger = $logger;

        $this->setInterval(INTERVAL);
    }

    protected function run($arguments)
    {
        // Check if indexing is enabled
        if ('0' === Util::getSystemConfig('memories.index.mode')) {
            return;
        }

        // Run for a maximum of 5 minutes
        $startTime = microtime(true);
        $this->service->continueCheck = function () use ($startTime) {
            return (microtime(true) - $startTime) < MAX_RUN_TIME;
        };

        // Index with static exiftool process
        // This is sub-optimal: the process may not be required at all.
        try {
            \OCA\Memories\Exif::ensureStaticExiftoolProc();
            $this->indexAllUsers();
        } catch (Service\ProcessClosedException $e) {
            $this->logger->warning('Memories: Indexing process closed before completion, will continue on next run.');
        } finally {
            \OCA\Memories\Exif::closeStaticExiftoolProc();
        }
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
                $this->service->indexUser($user->getUID());
            } catch (Service\ProcessClosedException $e) {
                throw $e;
            } catch (\Exception $e) {
                $this->logger->error('Indexing failed for user '.$user->getUID().': '.$e->getMessage());
            } catch (\Throwable $e) {
                $this->logger->error('[BUG] uncaught exception in memories: '.$e->getMessage());
            }
        });
    }
}
