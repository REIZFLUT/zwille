<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Read side (filtered, paginated listing for /admin/audit) and the chunked retention delete.
 *
 * @extends ServiceEntityRepository<AuditEntry>
 */
class AuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditEntry::class);
    }

    /**
     * Newest first. All filters are optional; an empty string counts as "no filter".
     */
    public function filteredQuery(?string $action, ?string $actor, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.occurredAt', \SortDirection::Descending)
            ->addOrderBy('a.id', \SortDirection::Descending);

        if (null !== $action && '' !== $action) {
            $qb->andWhere('a.action = :action')->setParameter('action', $action);
        }
        if (null !== $actor && '' !== $actor) {
            $qb->andWhere('a.actorLabel LIKE :actor')->setParameter('actor', '%'.$actor.'%');
        }
        if (null !== $from) {
            $qb->andWhere('a.occurredAt >= :from')->setParameter('from', $from);
        }
        if (null !== $to) {
            $qb->andWhere('a.occurredAt <= :to')->setParameter('to', $to);
        }

        return $qb;
    }

    /**
     * Deletes old entries in small chunks. One unbounded DELETE would hold SQLite's write
     * lock longer than the busy timeout and block concurrent writes for the whole duration,
     * so each chunk is selected first and then deleted by primary key - the same portable,
     * two-statement approach as {@see ClickRepository::deleteOlderThan()}.
     */
    public function deleteOlderThan(\DateTimeImmutable $cutoff, int $chunkSize = 5000): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $deleted = 0;

        while (true) {
            /** @var list<int> $ids */
            $ids = array_map(intval(...), $connection->executeQuery(
                'SELECT id FROM audit_entry WHERE occurred_at < ? ORDER BY id LIMIT ?',
                [$cutoff->format('Y-m-d H:i:s'), $chunkSize],
                [ParameterType::STRING, ParameterType::INTEGER],
            )->fetchFirstColumn());

            // Nothing older left.
            if ([] === $ids) {
                break;
            }

            $affected = $connection->executeStatement(
                'DELETE FROM audit_entry WHERE id IN (?)',
                [$ids],
                [ArrayParameterType::INTEGER],
            );
            $deleted += $affected;

            // A chunk shorter than the limit means this was the last one.
            if (\count($ids) < $chunkSize) {
                break;
            }
            usleep(10000);
        }

        return $deleted;
    }
}
