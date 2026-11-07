<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\ApiToken;
use App\Security\ApiScope;
use App\Security\Attribute\RequiresScope;
use App\Security\CurrentApiToken;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Enforces the scopes of the API token on every API action ({@see RequiresScope}) and the
 * extra write budget of agent tokens.
 *
 * Fail closed: an API action without a declared scope is not available to agent tokens at
 * all, so a new endpoint cannot become reachable for agents by forgetting the attribute.
 * Personal tokens hold every scope, for them nothing changes.
 */
final readonly class ApiScopeListener
{
    public function __construct(
        private CurrentApiToken $currentToken,
        #[Autowire(service: 'limiter.agent_write')]
        private RateLimiterFactoryInterface $writeLimiter,
        #[Autowire(service: 'limiter.agent_write_daily')]
        private RateLimiterFactoryInterface $dailyWriteLimiter,
        #[Autowire(service: 'limiter.agent_delete_daily')]
        private RateLimiterFactoryInterface $dailyDeleteLimiter,
    ) {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER, priority: 8)]
    public function onController(ControllerEvent $event): void
    {
        $token = $this->currentToken->get();
        if (null === $token || !$event->isMainRequest()) {
            return;
        }

        $required = $event->getAttributes(RequiresScope::class);
        if ([] === $required) {
            if ($token->isAgent()) {
                $this->deny($event, Response::HTTP_FORBIDDEN, 'scope_missing', 'This endpoint is not available to agent tokens.');
            }

            return;
        }

        foreach ($required as $attribute) {
            if (!$token->hasScope($attribute->scope)) {
                $this->deny($event, Response::HTTP_FORBIDDEN, 'scope_missing', \sprintf('This token lacks the scope "%s".', $attribute->scope), ['required_scope' => $attribute->scope]);

                return;
            }
        }

        if ($token->isAgent()) {
            $this->consumeWriteBudget($event, $token, array_map(static fn (RequiresScope $a): string => $a->scope, $required));
        }
    }

    /** @param list<string> $scopes */
    private function consumeWriteBudget(ControllerEvent $event, ApiToken $token, array $scopes): void
    {
        $limiters = [];
        if ([] !== array_intersect($scopes, ApiScope::WRITING)) {
            $limiters[] = $this->writeLimiter;
            $limiters[] = $this->dailyWriteLimiter;
        }
        if (\in_array(ApiScope::LINKS_DELETE, $scopes, true)) {
            $limiters[] = $this->dailyDeleteLimiter;
        }

        foreach ($limiters as $factory) {
            $limit = $factory->create('token-'.$token->getId())->consume();
            if (!$limit->isAccepted()) {
                $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
                $this->deny($event, Response::HTTP_TOO_MANY_REQUESTS, 'rate_limited', 'Write budget of this agent token exhausted.', [], ['Retry-After' => (string) $retryAfter]);

                return;
            }
        }
    }

    /**
     * @param array<string, string> $extra
     * @param array<string, string> $headers
     */
    private function deny(ControllerEvent $event, int $status, string $code, string $message, array $extra = [], array $headers = []): void
    {
        $response = new JsonResponse(['error' => $code, 'message' => $message] + $extra, $status, $headers);
        $event->setController(static fn (): JsonResponse => $response);
    }
}
