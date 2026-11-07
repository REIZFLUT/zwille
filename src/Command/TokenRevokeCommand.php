<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Break-glass revocation of API tokens from the shell: one token by ID, or every agent
 * token at once. Without arguments it lists the agent tokens.
 */
#[AsCommand(name: 'app:token:revoke', description: 'Revokes an API token by ID, or every agent token with --all-agents; lists agent tokens without arguments')]
final readonly class TokenRevokeCommand
{
    public function __construct(
        private EntityManagerInterface $em,
        private ApiTokenRepository $tokens,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('ID of the token to revoke')] ?int $id = null,
        #[Option('Revoke every agent token')] bool $allAgents = false,
    ): int {
        if ($allAgents) {
            $count = $this->tokens->revokeAllAgentTokens();
            $io->success(\sprintf('%d agent token(s) revoked.', $count));

            return Command::SUCCESS;
        }

        if (null !== $id) {
            $token = $this->tokens->find($id);
            if (null === $token) {
                $io->error('No such token.');

                return Command::FAILURE;
            }
            if ($token->isAgent()) {
                // Kept as revoked so its history stays traceable.
                $token->revoke();
            } else {
                $this->em->remove($token);
            }
            $this->em->flush();
            $io->success(\sprintf('Token %d ("%s") of %s revoked.', $id, $token->getName(), $token->getUser()->getEmail()));

            return Command::SUCCESS;
        }

        $rows = array_map(static fn (ApiToken $t): array => [
            (string) $t->getId(),
            $t->getName(),
            $t->getUser()->getEmail(),
            implode(', ', $t->getScopes()),
            $t->getExpiresAt()?->format('Y-m-d') ?? '',
            match (true) {
                $t->isRevoked() => 'revoked',
                $t->isExpired() => 'expired',
                default => 'active',
            },
        ], $this->tokens->findAgentTokens());
        $io->table(['ID', 'Name', 'User', 'Scopes', 'Expires', 'State'], $rows);

        return Command::SUCCESS;
    }
}
