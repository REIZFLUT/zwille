<?php

declare(strict_types=1);

namespace App\Link;

/**
 * Result of checking a stateless unlock token: the mac matched and the token is still valid,
 * the mac matched but the token has expired (a fresh form is served), or the mac did not match
 * (a tampered or foreign token, which must look like an unknown link).
 */
enum UnlockTokenStatus
{
    case Valid;
    case Expired;
    case Invalid;
}
