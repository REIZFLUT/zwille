<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Audit\AuditLogger;
use App\Entity\AuditEntry;
use App\Entity\Link;
use App\Entity\Tag;
use App\Entity\User;
use App\Security\SecretBox;
use OTPHP\TOTP;

/**
 * The audit trail is written by the auth/link listeners and a few explicit controller calls.
 * These tests drive the real HTTP surfaces and assert that the matching entries appear.
 */
final class AuditLogTest extends AppTestCase
{
    public function testSuccessfulLoginWritesAnEntry(): void
    {
        $user = $this->createUser('ada@example.com');

        $this->login('ada@example.com', self::PASSWORD);

        $entry = $this->lastEntry(AuditLogger::LOGIN);
        self::assertNotNull($entry, 'a login writes an auth.login entry');
        self::assertSame('ada@example.com', $entry->getActorLabel());
        self::assertSame($user->getId(), $entry->getActorId());
    }

    public function testFailedLoginRecordsTheAttemptedAddress(): void
    {
        $this->createUser('ada@example.com');

        $this->login('ghost@example.com', 'wrong');

        $entry = $this->lastEntry(AuditLogger::LOGIN_FAILED);
        self::assertNotNull($entry, 'a failed login writes an entry');
        self::assertNull($entry->getActorId(), 'nobody is authenticated on a failure');
        self::assertSame('ghost@example.com', $entry->getContext()['email'] ?? null);
    }

    public function testLogoutWritesAnEntry(): void
    {
        $this->client->loginUser($this->createUser('ada@example.com'));

        $token = $this->formToken('/links', '/logout');
        $this->client->request('POST', '/logout', ['_token' => $token], server: ['HTTP_ORIGIN' => 'http://'.self::ADMIN_HOST]);

        self::assertNotNull($this->lastEntry(AuditLogger::LOGOUT));
    }

    public function testApiTokenCreationWritesAnEntry(): void
    {
        $this->client->loginUser($this->createUser('ada@example.com'));
        $this->confirmWithPassword('tokens');

        $token = $this->formToken('/profile/tokens', '/profile/tokens');
        $this->client->request('POST', '/profile/tokens', ['name' => 'CRM', 'days' => '0', '_token' => $token]);

        $entry = $this->lastEntry(AuditLogger::TOKEN_CREATED);
        self::assertNotNull($entry, 'creating an API token writes an entry');
        self::assertSame('CRM', $entry->getContext()['name'] ?? null);
    }

    public function testTotpDisableWritesAnEntry(): void
    {
        $user = $this->createTotpUser();
        $this->client->loginUser($user);
        $this->confirmWithPassword('security');

        $token = $this->formToken('/profile/security', '/profile/totp/disable');
        $this->client->request('POST', '/profile/totp/disable', ['_token' => $token]);

        self::assertNotNull($this->lastEntry(AuditLogger::TWO_FA_DISABLED));
        self::assertFalse($this->fresh($user)->isTotpEnabled());
    }

    public function testLinkLifecycleWritesEntriesThroughTheListener(): void
    {
        $user = $this->createUser('ada@example.com');
        $this->createDomain('go.example.com', $user);
        $this->client->loginUser($user);

        // create via the quick flow (LinkManager::save -> LinkSavedEvent)
        $token = $this->formToken('/links/quick?url='.urlencode('https://www.example.com/x'), '/links/quick');
        $this->client->request('POST', '/links/quick', ['url' => 'https://www.example.com/x', 'title' => '', 'code' => '', '_token' => $token]);
        self::assertNotNull($this->lastEntry(AuditLogger::LINK_CREATED));

        $id = $this->linkId();

        // update via a bulk action (LinkManager::save -> LinkSavedEvent)
        $bulk = $this->formToken('/links', '/links/bulk');
        $this->client->request('POST', '/links/bulk', ['action' => 'deactivate', 'ids' => [(string) $id], '_token' => $bulk]);
        self::assertNotNull($this->lastEntry(AuditLogger::LINK_UPDATED));

        // delete via a bulk action (LinkManager::delete -> LinkDeletedEvent)
        $this->client->request('POST', '/links/bulk', ['action' => 'delete', 'ids' => [(string) $id], '_token' => $bulk]);
        self::assertNotNull($this->lastEntry(AuditLogger::LINK_DELETED));
    }

    public function testBulkDeletionWritesOneEntryPerLink(): void
    {
        $user = $this->createUser('ada@example.com');
        $domain = $this->createDomain('go.example.com', $user);
        $first = $this->createLink($user, $domain, 'aa')->getId();
        $second = $this->createLink($user, $domain, 'bb')->getId();
        $this->client->loginUser($user);

        $before = \count($this->entries(AuditLogger::LINK_DELETED));
        $token = $this->formToken('/links', '/links/bulk');
        $this->client->request('POST', '/links/bulk', ['action' => 'delete', 'ids' => [(string) $first, (string) $second], '_token' => $token]);

        self::assertSame($before + 2, \count($this->entries(AuditLogger::LINK_DELETED)), 'one entry per deleted link');
    }

    public function testBulkTagChangeWritesOneEntryForTheBatch(): void
    {
        $user = $this->createUser('ada@example.com');
        $domain = $this->createDomain('go.example.com', $user);
        $first = $this->createLink($user, $domain, 'aa')->getId();
        $second = $this->createLink($user, $domain, 'bb')->getId();

        $tag = new Tag($user, 'Kampagne Herbst');
        $this->em()->persist($tag);
        $this->em()->flush();

        $this->client->loginUser($user);
        $token = $this->formToken('/links', '/links/bulk');
        $this->client->request('POST', '/links/bulk', [
            '_token' => $token,
            'action' => 'tag_add',
            'tag' => (string) $tag->getId(),
            'ids' => [(string) $first, (string) $second],
        ]);

        // The bulk tag path writes through TagManager (one flush, no per-link event): the trail
        // gets a single entry for the batch, carrying the tag and how many links it touched.
        $entry = $this->lastEntry(AuditLogger::LINK_TAGS_CHANGED);
        self::assertNotNull($entry, 'a bulk tag change is recorded');
        self::assertSame('tag', $entry->getTargetType());
        self::assertSame($tag->getId(), $entry->getTargetId());
        self::assertSame(['tag' => 'Kampagne Herbst', 'added' => true, 'count' => 2], $entry->getContext());
    }

    public function testSecretishContextKeysAreDropped(): void
    {
        static::getContainer()->get(AuditLogger::class)->record('test.denylist', [
            'password' => 'hunter2',
            'totp_secret' => 'JBSWY3DP',
            'api_token' => 'zwl_123',
            'backup_code' => 'abcd-efgh',
            'email' => 'ada@example.com',
            'keep_me' => 'yes',
        ]);

        $entry = $this->lastEntry('test.denylist');
        self::assertNotNull($entry);
        self::assertSame(
            ['email' => 'ada@example.com', 'keep_me' => 'yes'],
            $entry->getContext(),
            'secret-like keys never reach the stored context',
        );
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('POST', '/login', [
            'email' => $email,
            'password' => $password,
            '_csrf_token' => 'csrf-token',
        ], server: ['HTTP_ORIGIN' => 'http://'.self::ADMIN_HOST]);
    }

    /** Opens the five minute confirmation window (see SensitiveConfirmation). */
    private function confirmWithPassword(string $target): void
    {
        $token = $this->formToken('/profile/confirm?target='.$target, '/profile/confirm');
        $this->client->request('POST', '/profile/confirm', [
            'password' => self::PASSWORD,
            'target' => $target,
            '_token' => $token,
        ]);
        self::assertResponseRedirects('http://'.self::ADMIN_HOST.'/profile/'.('tokens' === $target ? 'tokens' : 'security'));
    }

    private function createTotpUser(): User
    {
        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $user = $this->createUser('ada@example.com');
        $user->setTotpSecret($secret, static::getContainer()->get(SecretBox::class)->encrypt($secret))
            ->setTotpEnabled(true)
            ->replaceBackupCodes([TOTP::createFromSecret($secret)->now()]);
        $this->em()->flush();

        return $user;
    }

    /** Grabs a real CSRF token from the form that posts to $action on page $path. */
    private function formToken(string $path, string $action): string
    {
        $crawler = $this->client->request('GET', $path);
        $token = $crawler->filter(\sprintf('form[action="%s"] input[name=_token]', $action))->attr('value');
        self::assertNotNull($token, 'csrf token for '.$action);

        return $token;
    }

    private function linkId(): int
    {
        $link = $this->em()->getRepository(Link::class)->findOneBy([]);
        self::assertInstanceOf(Link::class, $link);

        return (int) $link->getId();
    }

    /** @return list<AuditEntry> */
    private function entries(string $action): array
    {
        return $this->em()->getRepository(AuditEntry::class)->findBy(['action' => $action]);
    }

    private function lastEntry(string $action): ?AuditEntry
    {
        $entries = $this->entries($action);

        return [] === $entries ? null : end($entries);
    }
}
