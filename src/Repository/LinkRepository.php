<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Domain;
use App\Entity\Link;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Link>
 */
class LinkRepository extends ServiceEntityRepository
{
    /** @var array<string, string> sort key => DQL expression */
    private const array SORT_FIELDS = [
        'created' => 'l.createdAt',
        'clicks' => 'l.clickCount',
        'last_click' => 'l.lastClickAt',
        'target' => 'LOWER(l.targetUrl)',
        'code' => 'LOWER(l.code)',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Link::class);
    }

    /**
     * Hot path of every redirect: one query that fetches the link together with its
     * domain and localisations, so the controller needs no separate domain lookup.
     */
    public function findForRedirectByHost(string $host, string $code): ?Link
    {
        return $this->createQueryBuilder('l')
            ->addSelect('d', 'loc')
            ->innerJoin('l.domain', 'd')
            ->leftJoin('l.localisations', 'loc')
            ->where('d.hostname = :host')
            ->andWhere('l.code = :code')
            ->setParameter('host', Domain::normalizeHostname($host))
            ->setParameter('code', mb_strtolower($code))
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function codeExists(Domain $domain, string $code): bool
    {
        return null !== $this->createQueryBuilder('l')
            ->select('l.id')
            ->where('l.domain = :domain')
            ->andWhere('l.code = :code')
            ->setParameter('domain', $domain)
            ->setParameter('code', mb_strtolower($code))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Links of the user, or all links for admins ($onlyOwn = false), optionally filtered and sorted.
     *
     * The sort key is whitelisted here: unknown keys fall back to the default (created/desc),
     * so callers that pass no sort (API, dashboard) keep the previous behaviour. A stable id
     * tie-breaker keeps pagination deterministic when the primary sort key ties.
     *
     * The status filter mirrors Link::isLive() and Link::isExpired(); unknown values mean "all".
     *
     * @param string      $sort   one of created, clicks, last_click, target, code
     * @param string      $dir    asc or desc
     * @param string|null $status live, inactive, expired or null for all
     * @param Tag|null    $tag    restrict to links carrying this tag (owner's own tag)
     */
    public function searchQuery(User $user, ?string $search = null, ?Domain $domain = null, bool $onlyOwn = true, string $sort = 'created', string $dir = 'desc', ?string $status = null, ?Tag $tag = null): QueryBuilder
    {
        $field = self::SORT_FIELDS[$sort] ?? self::SORT_FIELDS['created'];
        $direction = 'asc' === mb_strtolower($dir) ? \SortDirection::Ascending : \SortDirection::Descending;

        $qb = $this->createQueryBuilder('l')
            ->addSelect('d', 'o')
            ->innerJoin('l.domain', 'd')
            ->innerJoin('l.owner', 'o')
            ->orderBy($field, $direction)
            ->addOrderBy('l.id', \SortDirection::Descending);

        if ($onlyOwn) {
            $qb->andWhere('l.owner = :owner')->setParameter('owner', $user);
        }
        if (null !== $domain) {
            $qb->andWhere('l.domain = :domain')->setParameter('domain', $domain);
        }
        if (null !== $tag) {
            $qb->andWhere(':tag MEMBER OF l.tags')->setParameter('tag', $tag);
        }
        if (null !== $search && '' !== trim($search)) {
            // The internal note and the tag names are searchable too. LOWER()/LIKE behave
            // identically on SQLite and MariaDB/MySQL; the tag match uses a correlated
            // EXISTS so a link is never duplicated by the join (which would break paging).
            $qb->andWhere(
                'LOWER(l.code) LIKE :q OR LOWER(l.targetUrl) LIKE :q OR LOWER(l.title) LIKE :q OR LOWER(l.notes) LIKE :q'
                .' OR EXISTS (SELECT tt.id FROM '.Tag::class.' tt JOIN tt.links tl WHERE tl = l AND LOWER(tt.name) LIKE :q)',
            )->setParameter('q', '%'.mb_strtolower(trim($search)).'%');
        }
        match ($status) {
            'live' => $qb->andWhere('l.active = true AND d.active = true AND (l.expiresAt IS NULL OR l.expiresAt > :now)')
                ->setParameter('now', new \DateTimeImmutable()),
            'inactive' => $qb->andWhere('l.active = false'),
            'expired' => $qb->andWhere('l.expiresAt IS NOT NULL AND l.expiresAt <= :now')
                ->setParameter('now', new \DateTimeImmutable()),
            default => null,
        };

        return $qb;
    }

    /** @return list<Link> */
    public function findRecentFor(User $user, int $limit = 5): array
    {
        return $this->searchQuery($user)->setMaxResults($limit)->getQuery()->getResult();
    }

    public function countFor(?User $owner = null): int
    {
        $qb = $this->createQueryBuilder('l')->select('COUNT(l.id)');
        if (null !== $owner) {
            $qb->where('l.owner = :owner')->setParameter('owner', $owner);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countByDomain(Domain $domain): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.domain = :domain')
            ->setParameter('domain', $domain)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function transferOwnership(User $from, User $to): int
    {
        return $this->createQueryBuilder('l')
            ->update()
            ->set('l.owner', ':to')
            ->where('l.owner = :from')
            ->setParameter('to', $to)
            ->setParameter('from', $from)
            ->getQuery()
            ->execute();
    }
}
