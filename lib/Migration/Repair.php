<?php

declare(strict_types=1);

namespace OCA\Memories\Migration;

use OCA\Memories\Db\AddMissingIndices;
use OCA\Memories\Service\BinExt;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

final class Repair implements IRepairStep
{
    public function __construct(
        private IConfig $config,
        private AddMissingIndices $indices,
        private BinExt $binExt,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return 'Repair steps for Memories';
    }

    #[\Override]
    public function run(IOutput $output): void
    {
        $this->indices->run($output);
        $this->configureBinExt($output);
        $this->fixSystemConfigTypes($output);
    }

    public function configureBinExt(IOutput $output): void
    {
        // kill any instances of go-vod and exiftool
        $this->binExt->pkill($this->binExt->getName('go-vod'));
        $this->binExt->pkill($this->binExt->getName('exiftool'));

        // detect exiftool
        if ($path = $this->binExt->detectExiftool()) {
            $output->info("exiftool binary is configured: {$path}");
        } else {
            $output->warning('exiftool binary could not be configured');
        }

        // detect go-vod
        if ($path = $this->binExt->detectGoVod()) {
            $output->info("go-vod binary is configured: {$path}");
        } else {
            $output->warning('go-vod binary could not be configured');
        }

        // detect ffmpeg
        if ($path = $this->binExt->detectFFmpeg()) {
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

        // changed from string to string[]
        $connectKey = 'memories.vod.connect';
        $connect = $this->config->getSystemValue($connectKey, null);
        if (\is_string($connect)) {
            $output->info("Fixing system config value for {$connectKey}");
            $this->config->setSystemValue($connectKey, [trim($connect)]);
        }
    }
}
