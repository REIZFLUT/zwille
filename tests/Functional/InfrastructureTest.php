<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Command\DbBackupCommand;
use App\Entity\AdminIpRule;
use App\Entity\Click;
use App\Entity\Domain;
use App\Entity\Link;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\StrictSessionHandler;
use Symfony\Component\Process\ExecutableFinder;

final class InfrastructureTest extends KernelTestCase
{
    public function testConnectionIsConfiguredForTheDriver(): void
    {
        $connection = static::getContainer()->get(Connection::class);

        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            // The SQLite pragma middleware is the only session configuration the app applies.
            self::assertSame('wal', strtolower((string) $connection->fetchOne('PRAGMA journal_mode')));
            self::assertSame(1, (int) $connection->fetchOne('PRAGMA foreign_keys'));

            return;
        }

        // MySQL/MariaDB: there is no pragma equivalent (foreign keys and the journal are engine
        // features) and the SQLite pragma middleware must stay out of the way - a PRAGMA
        // statement would be a syntax error here. What the application relies on instead is a
        // usable session that speaks utf8mb4 (hostnames, target URLs and titles are utf8mb4).
        self::assertInstanceOf(AbstractMySQLPlatform::class, $connection->getDatabasePlatform());
        self::assertSame(1, (int) $connection->fetchOne('SELECT 1'));
        self::assertSame(
            'utf8mb4',
            strtolower((string) $connection->fetchOne('SELECT @@character_set_client')),
            'the connection charset must match the utf8mb4 schema',
        );
    }

    public function testSessionFilesLiveInProjectDirectory(): void
    {
        $container = static::getContainer();

        // Without an explicit save_path sessions end up in /var/lib/php/sessions, which
        // Debian's cron purges after the php.ini gc_maxlifetime (1440s) -> logout after ~24min.
        self::assertSame(
            $container->getParameter('kernel.project_dir').'/var/sessions',
            $container->getParameter('session.save_path'),
        );
        self::assertInstanceOf(
            StrictSessionHandler::class,
            $container->get('session.handler'),
            'the session handler must be session.handler.native_file',
        );

        // var/sessions is not cleaned by Debian's cron (it only knows /var/lib/php/sessions)
        // and php.ini sets gc_probability=0: without our own GC, expired sessions would
        // never be deleted and a stolen session id would stay valid forever.
        $options = $container->getParameter('session.storage.options');
        self::assertSame(1, (int) $options['gc_probability']);
        self::assertSame(100, (int) $options['gc_divisor']);
    }

    public function testAdminIpCommand(): void
    {
        $tester = $this->command('app:admin-ip');

        $tester->execute(['action' => 'allow', 'cidr' => '198.51.100.0/24', 'label' => 'Notfall']);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('198.51.100.0/24', $tester->getDisplay());
        self::assertSame(1, static::getContainer()->get('doctrine')->getRepository(AdminIpRule::class)->count([]));

        $tester->execute(['action' => 'allow', 'cidr' => 'kaputt']);
        self::assertSame(2, $tester->getStatusCode());

        $tester->execute(['action' => 'clear']);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('open to every address', $tester->getDisplay());
    }

    public function testUserAndDomainCommands(): void
    {
        $user = $this->command('app:user:create');
        $user->execute(['email' => 'cli@example.com', 'name' => 'CLI', '--admin' => true, '--invite' => true]);
        $user->assertCommandIsSuccessful();
        self::assertStringContainsString('/invite/', $user->getDisplay());

        $domain = $this->command('app:domain:add');
        $domain->execute(['hostname' => 'Kurz.Beispiel.de', '--default' => true]);
        $domain->assertCommandIsSuccessful();
        self::assertStringContainsString('kurz.beispiel.de', $domain->getDisplay());

        $domain->execute(['hostname' => 'admin.zwille.test']);
        self::assertSame(1, $domain->getStatusCode(), 'the admin host cannot become a short domain');
    }

    public function testBackupCommand(): void
    {
        $connection = static::getContainer()->get(Connection::class);
        $tester = $this->command('app:db:backup');
        $target = sys_get_temp_dir().'/zwille-backup-'.bin2hex(random_bytes(4)).'.sqlite';

        if (!$connection->getDatabasePlatform() instanceof SQLitePlatform) {
            // MariaDB/MySQL are dumped with the client tools. Without them the command must fail
            // loudly and leave nothing behind; with them the dump is a gzip file, owner-only.
            $target = sys_get_temp_dir().'/zwille-backup-'.bin2hex(random_bytes(4)).'.sql.gz';
            $hasTool = array_filter(DbBackupCommand::DUMP_TOOLS, static fn (string $tool): bool => null !== new ExecutableFinder()->find($tool));
            try {
                $tester->execute(['target' => $target]);
                if ([] === $hasTool) {
                    self::assertSame(Command::FAILURE, $tester->getStatusCode());
                    self::assertStringContainsString('client tools', $tester->getDisplay());
                    self::assertFileDoesNotExist($target, 'a failed backup must not leave a file behind');

                    return;
                }
                $tester->assertCommandIsSuccessful();
                self::assertFileDoesNotExist(substr($target, 0, -3), 'the plain intermediate dump is removed');
                self::assertStringContainsString('CREATE TABLE', (string) gzdecode((string) file_get_contents($target)));
                clearstatcache(true, $target);
                self::assertSame(0o600, fileperms($target) & 0o777, 'the dump contains secrets: owner read/write only');
            } finally {
                if (is_file($target)) {
                    unlink($target);
                }
            }

            return;
        }

        try {
            $tester->execute(['target' => $target]);
            $tester->assertCommandIsSuccessful();
            $copy = new \PDO('sqlite:'.$target);
            self::assertNotFalse($copy->query('SELECT COUNT(*) FROM link'));
            clearstatcache(true, $target);
            self::assertSame(0o600, fileperms($target) & 0o777, 'the copy contains secrets: owner read/write only');
        } finally {
            if (is_file($target)) {
                unlink($target);
            }
        }
    }

    public function testPruneCommand(): void
    {
        $tester = $this->command('app:clicks:prune');
        $tester->execute(['--days' => 30]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('0 clicks', $tester->getDisplay());
    }

    public function testPruneCommandDeletesOldClicksAndKeepsRecentOnes(): void
    {
        $tester = $this->command('app:clicks:prune');
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = (new User())->setEmail('prune@example.com')->setDisplayName('Prune');
        $domain = new Domain('go.example.com');
        $link = (new Link($user))->setDomain($domain)->setCode('prune')->setTargetUrl('https://www.example.com/');
        $em->persist($user);
        $em->persist($domain);
        $em->persist($link);

        $old = new \DateTimeImmutable('-40 days');
        for ($i = 0; $i < 12; ++$i) {
            $em->persist(new Click($link, $old->modify(\sprintf('+%d minutes', $i)), 'old-'.$i));
        }
        $recent = new \DateTimeImmutable('-1 day');
        for ($i = 0; $i < 3; ++$i) {
            $em->persist(new Click($link, $recent->modify(\sprintf('+%d minutes', $i)), 'new-'.$i));
        }
        $em->flush();

        $tester->execute(['--days' => 30]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('12 clicks', $tester->getDisplay());

        $em->clear();
        self::assertSame(3, $em->getRepository(Click::class)->count([]), 'only the 3 recent clicks survive');
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester(new Application(static::bootKernel())->find($name));
    }
}
