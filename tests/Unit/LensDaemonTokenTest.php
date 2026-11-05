<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Service\Lens;
use OCA\Memories\Tests\TestCase;

/**
 * @internal
 *
 * @covers \OCA\Memories\Service\Lens::daemonToken
 */
final class LensDaemonTokenTest extends TestCase
{
    public function testAcceptsDaemonLengthToken(): void
    {
        self::assertSame('0123456789abcdef', Lens::daemonToken('0123456789abcdef'));
    }

    public function testRejectsMissingShortAndWhitespaceTokens(): void
    {
        foreach ([null, '', 'short-token', '0123456789abcde', "0123456789abcdef\n", '0123456789abc def'] as $token) {
            try {
                Lens::daemonToken($token);
                self::fail('expected invalid token to be rejected');
            } catch (\RuntimeException $e) {
                self::assertSame('memories.lens.token is missing or invalid', $e->getMessage());
            }
        }
    }
}
