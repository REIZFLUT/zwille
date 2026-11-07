<?php

declare(strict_types=1);

namespace App\Link;

use App\Entity\Link;

/**
 * Hashing and checking the per-link unlock password.
 *
 * The hash (password_hash with the platform default, bcrypt today) is the only thing that is
 * stored on the link; the plain password lives in the request for the length of one call and
 * is never persisted, echoed or logged. Length rules live in the validator constraint
 * App\Validator\LinkPassword so the form and the API apply exactly the same ones.
 */
final readonly class LinkPassword
{
    public function hash(string $plain): string
    {
        return password_hash($plain, \PASSWORD_DEFAULT);
    }

    /** Constant-time check; a link without a hash never matches. */
    public function verify(string $plain, ?string $hash): bool
    {
        return null !== $hash && '' !== $hash && password_verify($plain, $hash);
    }

    public function verifyLink(Link $link, string $plain): bool
    {
        return $this->verify($plain, $link->getPasswordHash());
    }
}
