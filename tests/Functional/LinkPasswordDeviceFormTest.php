<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Link;
use App\Entity\LinkDeviceTarget;
use App\Entity\User;

/**
 * Package 4 through the web form: the write-only password field (set, replace, remove) and the
 * device-target collection, which must not lose the rows it does not touch.
 *
 * The fixtures clear the EntityManager after building them, so every request loads the link
 * (and its device targets, ordered by device) from the database - exactly the order the render
 * and the submit request see in production, which is what the positional collection mapping
 * relies on.
 */
final class LinkPasswordDeviceFormTest extends AppTestCase
{
    private const string UNLOCK_PASSWORD = 'geheim123';
    private const string HOST = 'go.example.com';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createUser('user@example.com');
        $this->client->loginUser($this->user);
    }

    public function testFormSetsPasswordAndDeviceTargets(): void
    {
        $domain = $this->createDomain(self::HOST, $this->user);

        $values = $this->values('/links/new', 'Link anlegen');
        $values['link']['targetUrl'] = 'https://www.example.com/start';
        $values['link']['domain'] = (string) $domain->getId();
        $values['link']['code'] = 'formtest';
        $values['link']['password'] = self::UNLOCK_PASSWORD;
        $values['link']['deviceTargets'] = [
            ['device' => 'android', 'targetUrl' => 'https://www.example.com/android'],
            ['device' => 'ios', 'targetUrl' => 'https://www.example.com/ios'],
        ];
        $this->post('/links/new', $values);

        self::assertResponseRedirects();
        $link = $this->link('formtest');
        self::assertTrue($link->hasPassword());
        self::assertTrue(password_verify(self::UNLOCK_PASSWORD, (string) $link->getPasswordHash()));
        self::assertSame(
            ['android' => 'https://www.example.com/android', 'ios' => 'https://www.example.com/ios'],
            $link->getDeviceTargetsMap(),
        );
    }

    public function testFormReplacesPasswordWithoutTouchingDeviceTargets(): void
    {
        $id = $this->storedLink();

        $values = $this->values('/links/'.$id.'/edit', 'Speichern');
        $values['link']['password'] = 'neuespasswort';
        $this->post('/links/'.$id.'/edit', $values);

        self::assertResponseRedirects();
        $link = $this->link('formtest');
        self::assertTrue(password_verify('neuespasswort', (string) $link->getPasswordHash()));
        self::assertFalse(password_verify(self::UNLOCK_PASSWORD, (string) $link->getPasswordHash()));
        self::assertSame(
            ['android' => 'https://www.example.com/android', 'ios' => 'https://www.example.com/ios'],
            $link->getDeviceTargetsMap(),
            'editing the password must not wipe the device targets',
        );
    }

    public function testEmptyPasswordFieldKeepsTheExistingHash(): void
    {
        $id = $this->storedLink();
        $before = (string) $this->link('formtest')->getPasswordHash();

        $values = $this->values('/links/'.$id.'/edit', 'Speichern');
        $values['link']['password'] = '';
        $values['link']['title'] = 'Neu';
        $this->post('/links/'.$id.'/edit', $values);

        self::assertResponseRedirects();
        $link = $this->link('formtest');
        self::assertSame($before, $link->getPasswordHash());
        self::assertSame('Neu', $link->getTitle());
    }

    public function testRemovePasswordCheckboxClearsProtection(): void
    {
        $id = $this->storedLink();

        $values = $this->values('/links/'.$id.'/edit', 'Speichern');
        $values['link']['removePassword'] = '1';
        $this->post('/links/'.$id.'/edit', $values);

        self::assertResponseRedirects();
        self::assertFalse($this->link('formtest')->hasPassword());
    }

    public function testPasswordCardExplainsOnceAndKeepsTheRemoveBoxInside(): void
    {
        $id = $this->storedLink();
        $crawler = $this->client->request('GET', '/links/'.$id.'/edit');
        $card = $crawler->filter('.card')->reduce(static fn ($c): bool => str_contains($c->filter('h2')->count() > 0 ? $c->filter('h2')->text() : '', 'Passwortschutz'));
        self::assertCount(1, $card);
        self::assertCount(1, $card->filter('input[name="link[removePassword]"]'), 'the remove box sits inside the card');
        self::assertSame(1, substr_count($crawler->html(), 'Leer lassen, um den Link offen'), 'the help text appears once');

        // Without a password there is nothing to remove - and no stray box below the card.
        $owner = $this->em()->find(User::class, $this->user->getId()) ?? self::fail('user');
        $domain = $this->em()->getRepository(\App\Entity\Domain::class)->findOneBy(['hostname' => self::HOST]) ?? self::fail('domain');
        $plain = $this->createLink($owner, $domain, 'plain');
        $crawler = $this->client->request('GET', '/links/'.$plain->getId().'/edit');
        self::assertCount(0, $crawler->filter('input[name="link[removePassword]"]'));
    }

    public function testFormEditsAndRemovesDeviceRowsWithoutLosingOthers(): void
    {
        $id = $this->storedLink();

        // Rows are rendered ordered by device, so index 0 is android and index 1 is ios.
        $values = $this->values('/links/'.$id.'/edit', 'Speichern');
        $values['link']['deviceTargets'][0]['targetUrl'] = 'https://www.example.com/android-neu';
        unset($values['link']['deviceTargets'][1]);
        $values['link']['deviceTargets'][2] = ['device' => 'desktop', 'targetUrl' => 'https://www.example.com/desktop'];
        $this->post('/links/'.$id.'/edit', $values);

        self::assertResponseRedirects();
        self::assertSame(
            ['android' => 'https://www.example.com/android-neu', 'desktop' => 'https://www.example.com/desktop'],
            $this->link('formtest')->getDeviceTargetsMap(),
        );
    }

    public function testFormRejectsTooShortAndTooLongPasswords(): void
    {
        $domain = $this->createDomain(self::HOST, $this->user);

        $values = $this->values('/links/new', 'Link anlegen');
        $values['link']['targetUrl'] = 'https://www.example.com/kurz';
        $values['link']['domain'] = (string) $domain->getId();
        $values['link']['code'] = 'kurz';
        $values['link']['password'] = 'abc';
        $this->post('/links/new', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.errors', 'mindestens 6');
        self::assertNull($this->em()->getRepository(Link::class)->findOneBy(['code' => 'kurz']));

        $values['link']['targetUrl'] = 'https://www.example.com/lang';
        $values['link']['code'] = 'lang';
        $values['link']['password'] = str_repeat('a', 73);
        $this->post('/links/new', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.errors', 'zu lang');
        self::assertNull($this->em()->getRepository(Link::class)->findOneBy(['code' => 'lang']));
    }

    /** A link with a password and the device targets android + ios, then detached to force DB loads. */
    private function storedLink(): int
    {
        $domain = $this->createDomain(self::HOST, $this->user);
        $link = $this->createLink($this->user, $domain, 'formtest', 'https://www.example.com/start');
        $link->setPasswordHash(password_hash(self::UNLOCK_PASSWORD, \PASSWORD_DEFAULT));
        $link->addDeviceTarget(new LinkDeviceTarget()->setDevice('android')->setTargetUrl('https://www.example.com/android'));
        $link->addDeviceTarget(new LinkDeviceTarget()->setDevice('ios')->setTargetUrl('https://www.example.com/ios'));
        $this->em()->flush();
        $id = (int) $link->getId();
        $this->em()->clear();

        return $id;
    }

    private function link(string $code): Link
    {
        $this->em()->clear();
        $link = $this->em()->getRepository(Link::class)->findOneBy(['code' => $code]);
        self::assertNotNull($link);

        return $link;
    }

    /** @return array<string, mixed> the current values of the given form, ready to be posted back */
    private function values(string $uri, string $button): array
    {
        $crawler = $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return $crawler->selectButton($button)->form()->getPhpValues();
    }

    /** @param array<string, mixed> $values */
    private function post(string $uri, array $values): void
    {
        $this->client->request('POST', $uri, $values, [], ['HTTP_ORIGIN' => 'http://'.self::ADMIN_HOST]);
    }
}
