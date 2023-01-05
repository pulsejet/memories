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
        // kill any instances of go-transcode and go-vod
        \OCA\Memories\Util::pkill('go-transcode');
        \OCA\Memories\Util::pkill('go-vod');
    }
}
