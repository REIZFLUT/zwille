<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Symmetric encryption for small secrets at rest (TOTP seeds), keyed from APP_SECRET.
 * Changing APP_SECRET makes stored TOTP seeds unreadable: affected users then set up
 * their authenticator app again (admins can reset 2FA).
 */
final readonly class SecretBox
{
    private string $key;

    public function __construct(#[Autowire('%kernel.secret%')] string $appSecret)
    {
        // "emsl-" dates from before the rename to Zwille. It is part of the key derivation:
        // changing it would make every stored TOTP seed unreadable, so it stays as it is.
        $this->key = sodium_crypto_generichash('emsl-secretbox|'.$appSecret, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(#[\SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $encrypted): ?string
    {
        $raw = base64_decode($encrypted, true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );

        return false === $plain ? null : $plain;
    }
}
