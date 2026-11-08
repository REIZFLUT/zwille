<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Audit\AuditLogger;
use App\Entity\AuditEntry;
use App\Entity\User;
use App\Settings\Settings;

/**
 * The /admin/audit page: listing, filtering, access control, and the settings-change entry.
 */
final class AuditAdminTest extends AppTestCase
{
    public function testAdminPageListsAndFiltersEntries(): void
    {
        $admin = $this->createUser('root@example.com', admin: true);
        $bob = $this->createUser('bob@example.com');
        $logger = static::getContainer()->get(AuditLogger::class);
        $logger->record(AuditLogger::LOGIN, actor: $admin);
        $logger->record(AuditLogger::LOGOUT, actor: $bob);

        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/audit');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Anmeldung');
        self::assertSelectorTextContains('table', 'root@example.com');
        self::assertSelectorTextContains('table', 'bob@example.com');

        // Filter by action: only the login row remains.
        $this->client->request('GET', '/admin/audit?action='.AuditLogger::LOGIN);
        self::assertSelectorTextContains('table', 'Anmeldung');
        self::assertSelectorTextNotContains('table', 'Abmeldung');

        // Filter by actor.
        $this->client->request('GET', '/admin/audit?actor=bob');
        self::assertSelectorTextContains('table', 'bob@example.com');
        self::assertSelectorTextNotContains('table', 'root@example.com');

        // Date range: a start far in the future hides everything, an end keeps it.
        $this->client->request('GET', '/admin/audit?from=2999-01-01');
        self::assertSelectorExists('.empty');
        $this->client->request('GET', '/admin/audit?to=2999-01-01');
        self::assertSelectorTextContains('table', 'Anmeldung');
    }

    public function testUnknownActionFilterFallsBackToListingEverything(): void
    {
        $admin = $this->createUser('root@example.com', admin: true);
        static::getContainer()->get(AuditLogger::class)->record(AuditLogger::LOGIN, actor: $admin);
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/audit?action=does.not.exist');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'root@example.com');
    }

    public function testNonAdminCannotOpenTheAuditPage(): void
    {
        $this->client->loginUser($this->createUser('user@example.com'));

        $this->client->request('GET', '/admin/audit');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminUserChangesAreRecorded(): void
    {
        $admin = $this->createUser('root@example.com', admin: true);
        $this->client->loginUser($admin);

        // create
        $this->client->request('POST', '/admin/users/new', ['admin_user' => [
            'displayName' => 'Neue Person',
            'email' => 'new@example.com',
            'active' => '1',
            '_token' => $this->formToken('/admin/users/new', 'admin_user'),
        ]]);
        self::assertResponseRedirects();
        self::assertNotNull($this->lastEntry(AuditLogger::USER_CREATED));

        $created = $this->em()->getRepository(User::class)->findOneBy(['email' => 'new@example.com']);
        self::assertInstanceOf(User::class, $created);
        $id = (int) $created->getId();

        // promote to admin and deactivate in one submit
        $this->client->request('POST', '/admin/users/'.$id, ['admin_user' => [
            'displayName' => 'Neue Person',
            'email' => 'new@example.com',
            'admin' => '1',
            '_token' => $this->formToken('/admin/users/'.$id, 'admin_user'),
        ]]);
        self::assertResponseRedirects();
        self::assertNotNull($this->lastEntry(AuditLogger::USER_ROLES_CHANGED));
        self::assertNotNull($this->lastEntry(AuditLogger::USER_DEACTIVATED));

        // delete (the trail outlives the account)
        $crawler = $this->client->request('GET', '/admin/users/'.$id);
        $deleteToken = $crawler->filter('form[action$="/admin/users/'.$id.'/delete"] input[name=_token]')->attr('value');
        self::assertNotNull($deleteToken);
        $this->client->request('POST', '/admin/users/'.$id.'/delete', ['transfer_to' => (string) $admin->getId(), '_token' => $deleteToken]);
        self::assertResponseRedirects();
        self::assertNotNull($this->lastEntry(AuditLogger::USER_DELETED));
    }

    public function testSettingsChangeRecordsOldToNewButNeverTheLogoValue(): void
    {
        $settings = static::getContainer()->get(Settings::class);
        $settings->save([Settings::LOGO => 'data:image/png;base64,SUPERSECRETLOGOBLOB']);

        $admin = $this->createUser('root@example.com', admin: true);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/settings');
        $token = $crawler->filter('form[name=settings] input[name="settings[_token]"]')->attr('value');
        self::assertNotNull($token, 'settings form csrf token');

        $this->client->request('POST', '/admin/settings', [
            'settings' => [
                'title' => 'Neuer Titel',
                'accent' => '#123456',
                'codeLength' => '7',
                'defaultRedirect' => '302',
                'removeLogo' => '1',
                '_token' => $token,
            ],
        ]);
        self::assertResponseRedirects();

        $entry = $this->em()->getRepository(AuditEntry::class)->findOneBy(['action' => AuditLogger::SETTINGS_CHANGED]);
        self::assertNotNull($entry, 'a settings change writes an entry');
        $context = $entry->getContext();
        $changes = $context['changes'] ?? null;
        self::assertIsArray($changes);
        self::assertSame('Neuer Titel', $changes['app.title']['to'] ?? null, 'a plain setting records old -> new');
        self::assertSame('Zwille', $changes['app.title']['from'] ?? null);
        self::assertArrayHasKey('branding.logo', $changes);
        self::assertArrayNotHasKey('to', $changes['branding.logo'], 'a secret-like setting records only "changed"');
        self::assertStringNotContainsString('SUPERSECRETLOGOBLOB', (string) json_encode($context), 'the raw value never reaches the trail');

        self::assertSame('Neuer Titel', $settings->get(Settings::TITLE));
        self::assertFalse($settings->hasLogo());
    }

    /** Grabs a real CSRF token from the named form rendered on $path. */
    private function formToken(string $path, string $formName): string
    {
        $crawler = $this->client->request('GET', $path);
        $token = $crawler->filter(\sprintf('form[name=%s] input[name="%s[_token]"]', $formName, $formName))->attr('value');
        self::assertNotNull($token, 'csrf token for '.$formName);

        return $token;
    }

    private function lastEntry(string $action): ?AuditEntry
    {
        $entries = $this->em()->getRepository(AuditEntry::class)->findBy(['action' => $action]);

        return [] === $entries ? null : end($entries);
    }
}
