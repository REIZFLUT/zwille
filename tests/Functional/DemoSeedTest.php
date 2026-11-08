<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Link;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:demo:seed fills an empty installation so the software can be evaluated right away:
 * users that can sign in, *.localhost short links that really redirect, filled statistics
 * and an audit trail. It must never touch an installation that already has data.
 */
final class DemoSeedTest extends AppTestCase
{
    private const string DEMO_PASSWORD = 'demo-password-123';

    public function testSeedsAWorkingDemoAndRefusesASecondRun(): void
    {
        $tester = $this->command();
        self::assertSame(Command::SUCCESS, $tester->execute(['--password' => self::DEMO_PASSWORD]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('mara@nordlicht.example', $display);
        self::assertStringContainsString(self::DEMO_PASSWORD, $display);

        $em = $this->em();
        $em->clear();
        self::assertSame(3, $em->getRepository(User::class)->count([]));
        self::assertSame(14, $em->getRepository(Link::class)->count([]));
        $connection = $em->getConnection();
        self::assertGreaterThan(2000, (int) $connection->fetchOne('SELECT COUNT(*) FROM click'));
        self::assertGreaterThan(10, (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_entry'));
        self::assertSame('go.localhost', $connection->fetchOne('SELECT hostname FROM domain WHERE is_default = ?', [true]));

        // The stored counter matches the click rows, so list and statistics agree.
        $herbst = $em->getRepository(Link::class)->findOneBy(['code' => 'herbst']);
        self::assertNotNull($herbst);
        self::assertSame($herbst->getClickCount(), (int) $connection->fetchOne('SELECT COUNT(*) FROM click WHERE link_id = ?', [$herbst->getId()]));
        self::assertLessThan(new \DateTimeImmutable('-20 days'), $herbst->getCreatedAt(), 'backdated so the charts have a history');

        // The administrator can sign in with the chosen password.
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form[method=post]')->last()->form(), ['email' => 'mara@nordlicht.example', 'password' => self::DEMO_PASSWORD]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Mara Lindqvist');

        // Short links redirect, with the language target applied.
        $this->shortRequest('go.localhost', '/herbst', ['Accept-Language' => 'en-US']);
        self::assertResponseRedirects('https://nordlicht.example/en/campaigns/autumn', 302);

        self::assertSame(Command::FAILURE, $this->command()->execute([]));
        self::assertSame(14, (int) $connection->fetchOne('SELECT COUNT(*) FROM link'), 'second run adds nothing');
    }

    public function testRefusesAnInstallationThatAlreadyHasAUser(): void
    {
        $this->createUser('someone@example.com');

        $tester = $this->command();
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('empty installation', $tester->getDisplay());
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM link'));
    }

    public function testRejectsAShortPassword(): void
    {
        $tester = $this->command();
        self::assertSame(Command::FAILURE, $tester->execute(['--password' => 'short']));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM app_user'));
    }

    private function command(): CommandTester
    {
        return new CommandTester(new Application(static::$kernel ?? self::bootKernel())->find('app:demo:seed'));
    }
}
