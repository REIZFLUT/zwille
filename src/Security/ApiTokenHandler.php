<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\ApiTokenRepository;
use App\Settings\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Resolves "Authorization: Bearer zwl_…|zwa_… (or pre-rename emsl_…|emsa_…)" to its user.
 *
 * Every refusal is the same 401 ("Invalid API token."), whatever the reason - expired,
 * revoked, wrong client address, agents switched off - so a stolen token does not tell its
 * holder which of the limits stopped it.
 */
final readonly class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private ApiTokenRepository $tokens,
        private EntityManagerInterface $em,
        private RequestStack $requestStack,
        private CurrentApiToken $currentToken,
        private Settings $settings,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = $this->tokens->findOneByPlainToken($accessToken);
        if (null === $token || !$token->isUsable() || !$token->getUser()->isActive()) {
            throw new BadCredentialsException('Invalid API token.');
        }
        if ($token->isAgent()) {
            $ip = $this->requestStack->getMainRequest()?->getClientIp();
            if (!$this->settings->getBool(Settings::AGENT_ACCESS) || !$token->allowsIp($ip)) {
                throw new BadCredentialsException('Invalid API token.');
            }
        }

        $lastUsed = $token->getLastUsedAt();
        // Writing on every request would turn each API read into a write transaction.
        // One write per minute is precise enough for the token's "last used" display.
        if (null === $lastUsed || $lastUsed < new \DateTimeImmutable('-60 seconds')) {
            $token->markUsed();
            $this->em->flush();
        }
        $this->currentToken->remember($token);
        $user = $token->getUser();

        return new UserBadge($user->getUserIdentifier(), static fn () => $user);
    }
}
