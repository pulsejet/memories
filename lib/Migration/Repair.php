<?php

declare(strict_types=1);

namespace OCA\Memories\Migration;

use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class Repair implements IRepairStep
{
    protected IConfig $config;

    public function __construct(IConfig $config)
    {
        $this->config = $config;
    }

    public function getName(): string
    {
        return 'Repair steps for Memories';
    }

    public function run(IOutput $output): void
    {
        // kill any instances of go-vod and exiftool
        \OCA\Memories\Util::pkill('go-vod');
        \OCA\Memories\Util::pkill('exiftool');

        // detect exiftool
        if ($path = \OCA\Memories\BinExt::detectExiftool()) {
            $output->info("exiftool binary is configured: {$path}");
        } else {
            $output->warning('exiftool binary could not be configured');
        }

        // detect go-vod
        if ($path = \OCA\Memories\BinExt::detectGoVod()) {
            $output->info("go-vod binary is configured: {$path}");
        } else {
            $output->warning('go-vod binary could not be configured');
        }

        // detect ffmpeg
        if ($path = \OCA\Memories\BinExt::detectFFmpeg()) {
            $output->info("ffmpeg binary is configured: {$path}");
        } else {
            $output->warning('ffmpeg binary could not be configured');
        }
    }
}
