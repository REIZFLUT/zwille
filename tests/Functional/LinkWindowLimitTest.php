<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ApiToken;
use App\Entity\Click;
use App\Entity\Link;
use App\Entity\User;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Package 3 (time window and click limits), through HTTP: the redirect decision, the form,
 * the list filter and the API.
 */
final class LinkWindowLimitTest extends AppTestCase
{
    private const string CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    // --- Redirect decision -------------------------------------------------

    public function testScheduledLinkLooksUnknownAndRecordsNoClick(): void
    {
        $owner = $this->createUser('owner@example.com');
        $domain = $this->createDomain('go.example.com');
        $link = $this->createLink($owner, $domain, 'geplant', 'https://www.example.com/');
        $link->setStartsAt(new \DateTimeImmutable('+1 day'));
        $this->em()->flush();

        $this->shortRequest('go.example.com', '/geplant', ['User-Agent' => self::CHROME]);

        // Indistinguishable from an unknown code: 404 and the not-found page echoing the path.
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('.card', 'geplant');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');

        $link = $this->fresh($link);
        self::assertSame(0, $link->getClickCount(), 'a scheduled link must not be counted');
        self::assertCount(0, $this->em()->getRepository(Click::class)->findBy(['link' => $link]));
    }

    public function testScheduledLinkWithFallbackRedirectsToIt(): void
    {
        $owner = $this->createUser('owner@example.com');
        $domain = $this->createDomain('go.example.com');
        $link = $this->createLink($owner, $domain, 'bald', 'https://www.example.com/');
        $link->setStartsAt(new \DateTimeImmutable('+1 day'))->setFallbackUrl('https://www.example.com/kommt-bald');
        $this->em()->flush();

        $this->shortRequest('go.example.com', '/bald', ['User-Agent' => self::CHROME]);

        self::assertResponseRedirects('https://www.example.com/kommt-bald', 302);
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');

        self::assertSame(0, $this->fresh($link)->getClickCount(), 'the fallback redirect is not a counted click');
    }

    public function testExhaustedLinkServesGoneAndRecordsNoFurtherClick(): void
    {
        $owner = $this->createUser('owner@example.com');
        $domain = $this->createDomain('go.example.com');
        $link = $this->createLink($owner, $domain, 'limitiert', 'https://www.example.com/');
        $link->setMaxClicks(1);
        $this->em()->flush();

        // First click: still allowed, recorded in kernel.terminate.
        $this->shortRequest('go.example.com', '/limitiert', ['User-Agent' => self::CHROME]);
        self::assertResponseRedirects('https://www.example.com/', 302);
        $link = $this->fresh($link);
        self::assertSame(1, $link->getClickCount());

        // Second click: the limit is reached -> 410 with its own page and no new click.
        $this->shortRequest('go.example.com', '/limitiert', ['User-Agent' => self::CHROME]);
        self::assertResponseStatusCodeSame(410);
        self::assertSelectorTextContains('.card', 'Klicklimit erreicht');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');

        $link = $this->fresh($link);
        self::assertSame(1, $link->getClickCount(), 'an exhausted link must not be counted again');
        self::assertCount(1, $this->em()->getRepository(Click::class)->findBy(['link' => $link]));
    }

    public function testPlainExpiredLinkKeepsTheGonePage(): void
    {
        $owner = $this->createUser('owner@example.com');
        $domain = $this->createDomain('go.example.com');
        $link = $this->createLink($owner, $domain, 'vorbei', 'https://www.example.com/');
        $link->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        $this->em()->flush();

        $this->shortRequest('go.example.com', '/vorbei', ['User-Agent' => self::CHROME]);

        self::assertResponseStatusCodeSame(410);
        // The historical message for the expired-by-date case is unchanged.
        self::assertSelectorTextContains('.card', 'deaktiviert oder ist abgelaufen');
    }

    // --- Form --------------------------------------------------------------

    public function testFormAcceptsWindowAndLimit(): void
    {
        $user = $this->createUser('user@example.com');
        $domain = $this->createDomain('go.example.com', $user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/links/new');
        $this->client->submitForm('Link anlegen', [
            'link[targetUrl]' => 'https://www.example.com/fenster',
            'link[domain]' => (string) $domain->getId(),
            'link[code]' => 'fenster',
            'link[startsAt]' => '2026-12-01T10:00',
            'link[maxClicks]' => '5',
            'link[fallbackUrl]' => 'https://www.example.com/bald',
        ], serverParameters: ['HTTP_ORIGIN' => 'http://'.self::ADMIN_HOST]);
        self::assertResponseRedirects();

        $link = $this->em()->getRepository(Link::class)->findOneBy(['code' => 'fenster']);
        self::assertNotNull($link);
        self::assertSame('2026-12-01', $link->getStartsAt()?->format('Y-m-d'));
        self::assertSame(5, $link->getMaxClicks());
        self::assertSame('https://www.example.com/bald', $link->getFallbackUrl());
    }

    public function testFormRejectsZeroClickLimit(): void
    {
        $user = $this->createUser('user@example.com');
        $domain = $this->createDomain('go.example.com', $user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/links/new');
        $this->client->submitForm('Link anlegen', [
            'link[targetUrl]' => 'https://www.example.com/null',
            'link[domain]' => (string) $domain->getId(),
            'link[code]' => 'nullklicks',
            'link[maxClicks]' => '0',
        ], serverParameters: ['HTTP_ORIGIN' => 'http://'.self::ADMIN_HOST]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.errors', 'mindestens 1');
        self::assertNull($this->em()->getRepository(Link::class)->findOneBy(['code' => 'nullklicks']));
    }

    public function testFormRejectsStartNotBeforeExpiry(): void
    {
        $user = $this->createUser('user@example.com');
        $domain = $this->createDomain('go.example.com', $user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/links/new');
        $this->client->submitForm('Link anlegen', [
            'link[targetUrl]' => 'https://www.example.com/umgekehrt',
            'link[domain]' => (string) $domain->getId(),
            'link[code]' => 'umgekehrt',
            'link[startsAt]' => '2026-12-01T10:00',
            'link[expiresAt]' => '2026-12-01T10:00',
        ], serverParameters: ['HTTP_ORIGIN' => 'http://'.self::ADMIN_HOST]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.errors', 'Startzeitpunkt');
        self::assertNull($this->em()->getRepository(Link::class)->findOneBy(['code' => 'umgekehrt']));
    }

    // --- List filter -------------------------------------------------------

    public function testListFilterFindsScheduledAndExhaustedLinks(): void
    {
        $user = $this->createUser('user@example.com');
        $domain = $this->createDomain('go.example.com', $user);
        $this->createLink($user, $domain, 'laeuft');
        $scheduled = $this->createLink($user, $domain, 'geplant');
        $scheduled->setStartsAt(new \DateTimeImmutable('+1 day'));
        $exhausted = $this->createLink($user, $domain, 'voll');
        $exhausted->setMaxClicks(1);
        $this->em()->flush();

        // The counter is written through DBAL in the real path; set it directly for the fixture.
        $this->em()->getConnection()->executeStatement(
            'UPDATE link SET click_count = 1 WHERE id = ?',
            [$exhausted->getId()],
        );
        $this->em()->clear();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/links?status=scheduled&sort=code&dir=asc');
        self::assertSame(['geplant'], $this->codes($crawler));
        self::assertSelectorTextContains('tbody .link-cell', 'Geplant');

        $crawler = $this->client->request('GET', '/links?status=exhausted&sort=code&dir=asc');
        self::assertSame(['voll'], $this->codes($crawler));
        self::assertSelectorTextContains('tbody .link-cell', 'Limit erreicht');

        // A scheduled/exhausted link must not show up as live.
        $crawler = $this->client->request('GET', '/links?status=live&sort=code&dir=asc');
        self::assertSame(['laeuft'], $this->codes($crawler));
    }

    // --- API ---------------------------------------------------------------

    public function testApiExposesAndValidatesWindowAndLimit(): void
    {
        $user = $this->createUser('api@example.com');
        $this->createDomain('go.example.com', $user);
        $token = $this->token($user);

        $this->api('POST', '/api/v1/links', $token, [
            'target_url' => 'https://www.example.com/fenster',
            'code' => 'fenster',
            'starts_at' => '2026-12-01T10:00:00+01:00',
            'max_clicks' => 5,
            'fallback_url' => 'https://www.example.com/bald',
        ]);
        self::assertResponseStatusCodeSame(201);
        $data = $this->json()['data'];
        self::assertSame(5, $data['max_clicks']);
        self::assertSame('https://www.example.com/bald', $data['fallback_url']);
        self::assertNotNull($data['starts_at']);
        $id = $data['id'];

        // Read back the fields.
        $this->api('GET', '/api/v1/links/'.$id, $token);
        self::assertResponseIsSuccessful();
        self::assertSame(5, $this->json()['data']['max_clicks']);

        // Update clears the limit again.
        $this->api('PATCH', '/api/v1/links/'.$id, $token, ['max_clicks' => null]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['data']['max_clicks']);

        // Validation: a limit below 1 and a non-http fallback are 422s with the right field.
        $this->api('POST', '/api/v1/links', $token, ['target_url' => 'https://www.example.com/a', 'max_clicks' => 0]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('max_clicks', $this->json()['violations'][0]['field']);

        $this->api('POST', '/api/v1/links', $token, ['target_url' => 'https://www.example.com/b', 'fallback_url' => 'ftp://example.com/x']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('fallback_url', $this->json()['violations'][0]['field']);
    }

    /** @return list<string> */
    private function codes(Crawler $crawler): array
    {
        $codes = [];
        foreach ($crawler->filter('tbody .link-cell .short') as $node) {
            $text = trim($node->textContent);
            $codes[] = substr($text, strrpos($text, '/') + 1);
        }

        return $codes;
    }

    private function token(User $user): string
    {
        [$token, $plain] = ApiToken::issue($user, 'test');
        $this->em()->persist($token);
        $this->em()->flush();

        return $plain;
    }

    /** @param array<string, mixed>|string|null $body */
    private function api(string $method, string $uri, string $token, array|string|null $body = null): void
    {
        $this->client->request($method, $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: \is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
