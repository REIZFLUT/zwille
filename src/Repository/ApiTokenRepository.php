<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    public function findOneByPlainToken(string $plainToken): ?ApiToken
    {
        return $this->findOneBy(['tokenHash' => ApiToken::hash($plainToken)]);
    }

    /** @return list<ApiToken> */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }

    /** @return list<ApiToken> every agent token of every user, newest first */
    public function findAgentTokens(): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('u')
            ->innerJoin('t.user', 'u')
            ->where('t.kind = :agent')
            ->setParameter('agent', ApiToken::KIND_AGENT)
            ->orderBy('t.createdAt', \SortDirection::Descending)
            ->addOrderBy('t.id', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /** Revokes every agent token that is not revoked yet; returns how many were revoked. */
    public function revokeAllAgentTokens(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->update()
            ->set('t.revokedAt', ':now')
            ->where('t.kind = :agent')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('agent', ApiToken::KIND_AGENT)
            ->getQuery()
            ->execute();
    }
}
