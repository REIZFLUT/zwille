<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Link;
use App\Entity\Tag;

/**
 * Numbers, dates and the tag picker follow the UI language: "1.234" and "05.03.2026" in German,
 * "1,234" and "5 Mar 2026" in English - not the German format everywhere.
 */
final class LocaleFormattingTest extends AppTestCase
{
    public function testLinkListFormatsCountsAndDatesPerLanguage(): void
    {
        $user = $this->createUser('user@example.com');
        $link = $this->createLink($user, $this->createDomain('go.example.com', $user), 'popular');
        $this->em()->createQuery('UPDATE '.Link::class.' l SET l.clickCount = 1234, l.createdAt = :at WHERE l.id = :id')
            ->execute(['at' => new \DateTimeImmutable('2026-03-05 12:00:00'), 'id' => $link->getId()]);
        $this->em()->clear();
        $this->client->loginUser($user);

        $this->client->request('GET', '/links', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9']);
        $row = $this->client->getCrawler()->filter('tbody tr')->first()->text();
        self::assertStringContainsString('1,234', $row);
        self::assertStringContainsString('5 Mar 2026', $row);

        $this->client->request('GET', '/links', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);
        $row = $this->client->getCrawler()->filter('tbody tr')->first()->text();
        self::assertStringContainsString('1.234', $row);
        self::assertStringContainsString('05.03.2026', $row);
    }

    public function testTagsArePickedWithToggleChips(): void
    {
        $user = $this->createUser('user@example.com');
        $this->createDomain('go.example.com', $user);
        foreach (['Campaign', 'Print'] as $name) {
            $this->em()->persist(new Tag($user, $name));
        }
        $this->em()->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/links/new');
        $chips = $crawler->filter('.tag-choices label.tag-choice');
        self::assertCount(2, $chips);
        self::assertCount(2, $crawler->filter('.tag-choices input[type=checkbox]'), 'real checkboxes: keyboard and screen readers keep working');
        self::assertCount(0, $crawler->filter('select[multiple]'));
    }
}
