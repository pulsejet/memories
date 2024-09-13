<?php

declare(strict_types=1);

namespace OCA\Memories\Migration;

use OCA\Memories\Db\AddMissingIndices;
use OCA\Memories\Service\BinExt;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class Repair implements IRepairStep
{
    public function __construct(protected IConfig $config) {}

    public function getName(): string
    {
        return 'Repair steps for Memories';
    }

    public function run(IOutput $output): void
    {
        AddMissingIndices::run($output);
        $this->configureBinExt($output);
        $this->fixSystemConfigTypes($output);
    }

    public function configureBinExt(IOutput $output): void
    {
        // kill any instances of go-vod and exiftool
        BinExt::pkill(BinExt::getName('go-vod'));
        BinExt::pkill(BinExt::getName('exiftool'));

        // detect exiftool
        if ($path = BinExt::detectExiftool()) {
            $output->info("exiftool binary is configured: {$path}");
        } else {
            $output->warning('exiftool binary could not be configured');
        }

        // detect go-vod
        if ($path = BinExt::detectGoVod()) {
            $output->info("go-vod binary is configured: {$path}");
        } else {
            $output->warning('go-vod binary could not be configured');
        }

        // detect ffmpeg
        if ($path = BinExt::detectFFmpeg()) {
            $output->info("ffmpeg binary is configured: {$path}");
        } else {
            $output->warning('ffmpeg binary could not be configured');
        }
    }

    public function fixSystemConfigTypes(IOutput $output): void
    {
        // https://github.com/pulsejet/memories/issues/1168
        $intKeys = [
            'preview_max_x',
            'preview_max_y',
            'jpeg_quality',
        ];
        foreach ($intKeys as $key) {
            $value = $this->config->getSystemValue($key, null);
            if (null !== $value && !\is_int($value)) {
                $output->info("Fixing system config value for {$key}");
                $this->config->setSystemValue($key, (int) $value ?: 2048);
            }
        }
    }
}
