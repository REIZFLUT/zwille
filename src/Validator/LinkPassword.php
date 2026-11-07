<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Length rules for a link's unlock password: at least six characters, at most 72 bytes
 * (the bcrypt input limit - beyond that PHP would silently truncate it). Null and the empty
 * string pass, so "leave empty to keep the current password" stays valid.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::TARGET_PARAMETER)]
final class LinkPassword extends Constraint
{
    public const int MIN_LENGTH = 6;
    public const int MAX_BYTES = 72;

    public string $tooShortMessage = 'link.password.too_short';
    public string $tooLongMessage = 'link.password.too_long';
}
