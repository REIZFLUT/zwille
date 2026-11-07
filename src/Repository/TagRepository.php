<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tag;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tag>
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /** @return list<Tag> */
    public function findByOwner(User $owner): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('LOWER(t.name)', \SortDirection::Ascending)
            ->addOrderBy('t.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Owner-scoped lookup: a foreign or unknown id resolves to null, so the caller can
     * answer with a 404 instead of touching somebody else's tag.
     */
    public function findOwned(User $owner, int $id): ?Tag
    {
        return $this->findOneBy(['id' => $id, 'owner' => $owner]);
    }

    public function findOneByOwnerName(User $owner, string $name): ?Tag
    {
        return $this->findOneBy(['owner' => $owner, 'normalizedName' => mb_strtolower(trim($name))]);
    }

    /**
     * Link count and summed click count per tag, one aggregate query over link_tag -> link.
     * No entity hydration: getArrayResult() returns scalars only, and the LEFT JOIN keeps
     * tags without links in the result with 0/0.
     *
     * @return array<int, array{links: int, clicks: int}> keyed by tag id
     */
    public function totals(?User $owner = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.id AS id, COUNT(l.id) AS links, COALESCE(SUM(l.clickCount), 0) AS clicks')
            ->leftJoin('t.links', 'l')
            ->groupBy('t.id');
        if (null !== $owner) {
            $qb->andWhere('t.owner = :owner')->setParameter('owner', $owner);
        }

        /** @var list<array{id: int|string, links: int|string, clicks: int|string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['id']] = ['links' => (int) $row['links'], 'clicks' => (int) $row['clicks']];
        }

        return $totals;
    }
}
