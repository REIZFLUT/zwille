<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Domain;
use App\Entity\User;
use App\Security\AdminAccess;
use App\Security\CurrentApiToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A user may create links on active domains assigned to them; admins on all active domains.
 * An API token limited to certain domains narrows that further.
 *
 * @extends Voter<string, Domain>
 */
final class DomainVoter extends Voter
{
    public const string USE = 'DOMAIN_USE';

    public function __construct(
        private readonly AdminAccess $adminAccess,
        private readonly CurrentApiToken $apiToken,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::USE === $attribute && $subject instanceof Domain;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject->isActive() || false === $this->apiToken->get()?->allowsDomain($subject)) {
            return false;
        }
        if ($this->adminAccess->isAdmin($user)) {
            return true;
        }

        foreach ($user->getDomains() as $domain) {
            if ($domain->getId() === $subject->getId()) {
                return true;
            }
        }

        return false;
    }
}
