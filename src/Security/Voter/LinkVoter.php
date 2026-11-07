<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Link;
use App\Entity\User;
use App\Security\AdminAccess;
use App\Security\CurrentApiToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Owners manage their links, admins (from allowed addresses) manage all links. An API token
 * limited to certain domains only reaches the links on those domains.
 *
 * @extends Voter<string, Link>
 */
final class LinkVoter extends Voter
{
    public const string MANAGE = 'LINK_MANAGE';

    public function __construct(
        private readonly AdminAccess $adminAccess,
        private readonly CurrentApiToken $apiToken,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof Link;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User || false === $this->apiToken->get()?->allowsDomain($subject->getDomain())) {
            return false;
        }

        return $this->adminAccess->isAdmin($user) || $subject->getOwner()->getId() === $user->getId();
    }
}
