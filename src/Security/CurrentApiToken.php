<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiToken;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The API token that authenticated the current request, if any.
 *
 * Kept as an attribute of the main request rather than in a service property, so it can never
 * leak from one request into the next (long-running workers, the test client).
 */
final readonly class CurrentApiToken
{
    public const string ATTRIBUTE = '_api_token';

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function remember(ApiToken $token): void
    {
        $this->requestStack->getMainRequest()?->attributes->set(self::ATTRIBUTE, $token);
    }

    public function get(): ?ApiToken
    {
        $token = $this->requestStack->getMainRequest()?->attributes->get(self::ATTRIBUTE);

        return $token instanceof ApiToken ? $token : null;
    }

    /** True while an agent token is acting: no admin rights, only its own scopes and limits. */
    public function isAgent(): bool
    {
        return true === $this->get()?->isAgent();
    }
}
