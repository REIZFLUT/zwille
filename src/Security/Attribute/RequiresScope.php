<?php

declare(strict_types=1);

namespace App\Security\Attribute;

/**
 * Marks an API action with the token scope it needs. API actions without this attribute are
 * closed to agent tokens (fail closed), see {@see \App\EventListener\ApiScopeListener}.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class RequiresScope
{
    public function __construct(public string $scope)
    {
    }
}
