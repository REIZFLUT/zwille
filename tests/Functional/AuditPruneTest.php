<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:audit:prune keeps the trail bounded: only entries older than the retention window are
 * removed, in chunks, and the deleted count is reported.
 */
final class AuditPruneTest extends AppTestCase
{
    public function testPrunesOnlyOldEntriesAndReportsTheCount(): void
    {
        $connection = $this->em()->getConnection();
        $this->seed($connection, '-400 days', 2);
        $this->seed($connection, '-10 days', 1);

        $tester = $this->prune();
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('2 audit entries older than 365 days deleted', $tester->getDisplay());
        self::assertSame(1, $this->countEntries(), 'the recent entry survives');
    }

    public function testDaysOptionOverridesTheRetention(): void
    {
        $connection = $this->em()->getConnection();
        $this->seed($connection, '-10 days', 1);
        $this->seed($connection, '-2 days', 1);

        $tester = $this->prune();
        $tester->execute(['--days' => 5]);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('1 audit entries older than 5 days deleted', $tester->getDisplay());
        self::assertSame(1, $this->countEntries());
    }

    public function testZeroDaysDisablesRetention(): void
    {
        $this->seed($this->em()->getConnection(), '-400 days', 1);

        $tester = $this->prune();
        $tester->execute(['--days' => 0]);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('disabled', $tester->getDisplay());
        self::assertSame(1, $this->countEntries());
    }

    private function prune(): CommandTester
    {
        return new CommandTester(new Application(static::bootKernel())->find('app:audit:prune'));
    }

    /**
     * AuditEntry stamps occurred_at itself, so the retention test inserts rows directly with
     * a chosen timestamp. The connection is DAMA's static one, so the command sees them.
     */
    private function seed(Connection $connection, string $relative, int $count): void
    {
        $when = (new \DateTimeImmutable('today'))->modify($relative)->setTime(12, 0);
        for ($i = 0; $i < $count; ++$i) {
            $connection->executeStatement(
                'INSERT INTO audit_entry (occurred_at, action, actor_label, context) VALUES (?, ?, ?, ?)',
                [$when->format('Y-m-d H:i:s'), 'test.seed', 'seed@example.com', '[]'],
            );
        }
    }

    private function countEntries(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_entry');
    }
}
