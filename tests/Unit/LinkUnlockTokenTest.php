<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Link\LinkUnlockToken;
use App\Link\UnlockTokenStatus;
use PHPUnit\Framework\TestCase;

/**
 * The stateless unlock token: a genuine token is valid, a rewritten mac or one minted for
 * another code is invalid (must look like an unknown link), an overdue token is expired.
 */
final class LinkUnlockTokenTest extends TestCase
{
    private LinkUnlockToken $tokens;

    protected function setUp(): void
    {
        $this->tokens = new LinkUnlockToken('unit-test-secret');
    }

    public function testIssuedTokenIsValidAndCarriesTheLifetime(): void
    {
        $now = new \DateTimeImmutable('2026-11-07T12:00:00+00:00');
        $token = $this->tokens->issue('geheim', $now);

        self::assertSame($now->getTimestamp() + LinkUnlockToken::LIFETIME, $token['expiry']);
        self::assertSame(1800, $token['expiry'] - $now->getTimestamp());
        self::assertSame(UnlockTokenStatus::Valid, $this->tokens->verify('geheim', $token['expiry'], $token['mac'], $now));
    }

    public function testTamperedMacIsInvalid(): void
    {
        $token = $this->tokens->issue('geheim');
        $tampered = substr($token['mac'], 0, -1).('0' === $token['mac'][-1] ? '1' : '0');

        self::assertSame(UnlockTokenStatus::Invalid, $this->tokens->verify('geheim', $token['expiry'], $tampered));
    }

    public function testForeignCodeIsInvalid(): void
    {
        // A mac minted for "geheim" must not verify a different code, even with the same expiry.
        $token = $this->tokens->issue('geheim');

        self::assertSame(UnlockTokenStatus::Invalid, $this->tokens->verify('anderer-code', $token['expiry'], $token['mac']));
    }

    public function testTamperedExpiryIsInvalid(): void
    {
        $now = new \DateTimeImmutable('2026-11-07T12:00:00+00:00');
        $token = $this->tokens->issue('geheim', $now);

        // Rewriting the expiry (to extend the form's life) breaks the mac -> invalid, not valid.
        self::assertSame(
            UnlockTokenStatus::Invalid,
            $this->tokens->verify('geheim', $token['expiry'] + 3600, $token['mac'], $now),
        );
    }

    public function testExpiredTokenIsExpiredNotInvalid(): void
    {
        $now = new \DateTimeImmutable('2026-11-07T12:00:00+00:00');
        $token = $this->tokens->issue('geheim', $now->modify('-1 hour'));

        self::assertSame(UnlockTokenStatus::Expired, $this->tokens->verify('geheim', $token['expiry'], $token['mac'], $now));
    }
}
