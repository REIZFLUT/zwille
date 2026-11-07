<?php

declare(strict_types=1);

namespace App\Link;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stateless unlock token for a password-protected short link.
 *
 * A short-link host has no session and sets no cookie (see ShortLinkHostEventListener /
 * ShortLinkHostListener), so the unlock form cannot carry a CSRF token. Instead it carries a
 * signed token: the mac is HMAC-SHA256 over the short code and the expiry timestamp, keyed with
 * APP_SECRET, and is compared with hash_equals. The token proves nothing on its own - the
 * password still has to match - it only binds the form to this link and limits how long the
 * form stays valid.
 *
 * Issue and verify both take the short code, so a token minted for one link is worthless on
 * another; a forged or foreign mac is treated exactly like an unknown link.
 */
final readonly class LinkUnlockToken
{
    /** How long an issued form stays valid before a fresh one is served. */
    public const int LIFETIME = 1800;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    /**
     * @return array{expiry: int, mac: string} the values the form echoes back on submit
     */
    public function issue(string $code, ?\DateTimeImmutable $now = null): array
    {
        $expiry = ($now ?? new \DateTimeImmutable())->getTimestamp() + self::LIFETIME;

        return ['expiry' => $expiry, 'mac' => $this->mac($code, $expiry)];
    }

    public function verify(string $code, int $expiry, string $mac, ?\DateTimeImmutable $now = null): UnlockTokenStatus
    {
        // Check the signature before the lifetime: a tampered expiry also fails the mac and must
        // stay indistinguishable from an unknown link, while a genuine but overdue token simply
        // gets a fresh form.
        if (!hash_equals($this->mac($code, $expiry), $mac)) {
            return UnlockTokenStatus::Invalid;
        }

        if ($expiry < ($now ?? new \DateTimeImmutable())->getTimestamp()) {
            return UnlockTokenStatus::Expired;
        }

        return UnlockTokenStatus::Valid;
    }

    /** The separator cannot occur in a code, so (code, expiry) pairs stay unambiguous. */
    private function mac(string $code, int $expiry): string
    {
        return hash_hmac('sha256', $code."\0".$expiry, $this->secret);
    }
}
