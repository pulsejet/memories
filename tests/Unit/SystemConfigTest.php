<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Tests\Injected;
use OCA\Memories\Tests\TestCase;
use OCP\IConfig;

/**
 * @internal
 *
 * @covers \OCA\Memories\Settings\SystemConfig
 */
final class SystemConfigTest extends TestCase
{
    #[Injected]
    private SystemConfig $systemConfig;

    #[Injected]
    private IConfig $config;

    protected function tearDown(): void
    {
        $this->config->deleteSystemValue('memories.vod.connect');
        parent::tearDown();
    }

    public function testListKeyAcceptsLegacyString(): void
    {
        $this->config->setSystemValue('memories.vod.connect', ' go-vod:47788 ');

        self::assertSame(['go-vod:47788'], $this->systemConfig->get('memories.vod.connect'));
    }

    public function testListKeyReturnsList(): void
    {
        $this->config->setSystemValue('memories.vod.connect', ['a:47788', 'b:47788']);

        self::assertSame(['a:47788', 'b:47788'], $this->systemConfig->get('memories.vod.connect'));
    }

    public function testListKeyRejectsOtherTypes(): void
    {
        $this->config->setSystemValue('memories.vod.connect', 47788);

        $this->expectException(\InvalidArgumentException::class);
        $this->systemConfig->get('memories.vod.connect');
    }
}
