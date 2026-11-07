<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Click;
use App\Entity\Domain;
use App\Entity\Link;
use App\Entity\LinkDeviceTarget;
use App\Entity\LinkLocalisation;
use App\Link\LinkUnlockToken;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Package 4 through HTTP on a short-link host: the unlock gate (form, wrong/correct password,
 * throttle, tampered and expired tokens, availability precedence) and the device resolution.
 */
final class RedirectPasswordTest extends AppTestCase
{
    private const string CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
    private const string IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    private const string ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36';
    private const string BOT = 'Googlebot/2.1 (+http://www.google.com/bot.html)';
    private const string UNLOCK_PASSWORD = 'geheim123';
    private const string HOST = 'go.example.com';
    private const string IP = '198.51.100.42';

    public function testProtectedFormDoesNotLeakTargetOrHash(): void
    {
        $link = $this->protectedLink('geheim', 'https://www.example.com/secret');

        $this->shortRequest(self::HOST, '/geheim', ['User-Agent' => self::CHROME], 'GET', ['REMOTE_ADDR' => self::IP]);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseNotHasCookie('emsl_session');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('https://www.example.com/secret', $html, 'the target must not be in the HTML');
        self::assertStringNotContainsString((string) $link->getPasswordHash(), $html, 'the hash must not be in the HTML');
        self::assertStringNotContainsString('$2y$', $html);

        $crawler = $this->client->getCrawler();
        self::assertCount(1, $crawler->filter('form input[name="password"]'));
        self::assertSame('geheim', $crawler->filter('input[name="code"]')->attr('value'));
        self::assertNotSame('', (string) $crawler->filter('input[name="mac"]')->attr('value'));
    }

    public function testWrongPasswordRerendersWithOneErrorAndCountsAgainstTheLimiter(): void
    {
        $this->useGate(2);
        $link = $this->protectedLink('geheim');
        $form = $this->form('geheim');

        $this->postUnlock('geheim', ['password' => 'falsch-1'] + $form);
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('.errors', 'stimmt nicht');
        $this->assertNoClick($link);

        // The second and last token is used up; the third attempt is throttled.
        $this->postUnlock('geheim', ['password' => 'falsch-2'] + $form);
        self::assertResponseStatusCodeSame(200);
        $this->postUnlock('geheim', ['password' => 'falsch-3'] + $form);
        self::assertResponseStatusCodeSame(429, 'after the limit the gate answers with its own page');

        $this->assertNoClick($link);
    }

    public function testCorrectPasswordRedirectsAndRecordsExactlyOneClick(): void
    {
        $this->useGate(5);
        $link = $this->protectedLink('geheim', 'https://www.example.com/secret');
        $form = $this->form('geheim');

        $this->postUnlock('geheim', ['password' => self::UNLOCK_PASSWORD] + $form);

        self::assertResponseRedirects('https://www.example.com/secret', 302);
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');

        $link = $this->fresh($link);
        self::assertSame(1, $link->getClickCount());
        self::assertCount(1, $this->em()->getRepository(Click::class)->findBy(['link' => $link]));
    }

    public function testThrottleServesItsOwnPageAndNeverTheForm(): void
    {
        $this->useGate(1);
        $this->protectedLink('geheim');
        $form = $this->form('geheim');

        $this->postUnlock('geheim', ['password' => 'falsch'] + $form);
        self::assertResponseStatusCodeSame(200, 'a wrong password answers the form (200), distinct from the throttle');

        $this->postUnlock('geheim', ['password' => 'falsch'] + $form);
        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('.card', 'Zu viele Versuche');
        self::assertStringNotContainsString('name="password"', (string) $this->client->getResponse()->getContent());

        // A fresh GET is throttled too: the form is not rendered again.
        $this->shortRequest(self::HOST, '/geheim', ['User-Agent' => self::CHROME], 'GET', ['REMOTE_ADDR' => self::IP]);
        self::assertResponseStatusCodeSame(429);
        self::assertStringNotContainsString('name="password"', (string) $this->client->getResponse()->getContent());
    }

    public function testTamperedMacAndForeignCodeLookLikeAnUnknownLink(): void
    {
        $this->protectedLink('geheim');
        $form = $this->form('geheim');

        $form['mac'] = str_repeat('a', 64);
        $this->postUnlock('geheim', ['password' => self::UNLOCK_PASSWORD] + $form);
        self::assertResponseStatusCodeSame(404, 'a rewritten mac must be indistinguishable from an unknown link');
        self::assertStringNotContainsString('name="password"', (string) $this->client->getResponse()->getContent());

        // A token minted for another link's code, posted to this link's path.
        $this->protectedLink('zwei', 'https://www.example.com/zwei');
        $token = static::getContainer()->get(LinkUnlockToken::class)->issue('zwei');
        $this->postUnlock('geheim', ['code' => 'zwei', 'expiry' => $token['expiry'], 'mac' => $token['mac'], 'password' => self::UNLOCK_PASSWORD]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testExpiredMacServesAFreshForm(): void
    {
        $this->protectedLink('geheim');
        $tokens = static::getContainer()->get(LinkUnlockToken::class);
        $expired = $tokens->issue('geheim', new \DateTimeImmutable('-1 hour'));

        $this->postUnlock('geheim', ['code' => 'geheim', 'expiry' => $expired['expiry'], 'mac' => $expired['mac'], 'password' => self::UNLOCK_PASSWORD]);

        self::assertResponseStatusCodeSame(200);
        $crawler = $this->client->getCrawler();
        self::assertCount(1, $crawler->filter('input[name="password"]'));
        self::assertNotSame($expired['mac'], (string) $crawler->filter('input[name="mac"]')->attr('value'), 'a new token is issued');
    }

    public function testMissingPasswordShowsTheFormAgain(): void
    {
        $this->useGate(5);
        $this->protectedLink('geheim');
        $form = $this->form('geheim');

        $this->postUnlock('geheim', $form);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->client->getCrawler()->filter('input[name="password"]'));
    }

    public function testScheduledProtectedLinkWinsAvailability(): void
    {
        $link = $this->protectedLink('geheim');
        $link->setStartsAt(new \DateTimeImmutable('+1 day'));
        $this->em()->flush();

        $this->shortRequest(self::HOST, '/geheim', ['User-Agent' => self::CHROME]);

        self::assertResponseStatusCodeSame(404, 'availability decides before the password form');
        self::assertStringNotContainsString('name="password"', (string) $this->client->getResponse()->getContent());
        $this->assertNoClick($link);
    }

    public function testDeviceUserAgentsGetTheirDeviceTarget(): void
    {
        $owner = $this->createUser('owner@example.com');
        $domain = $this->createDomain(self::HOST);
        $this->createLink($owner, $domain, 'geraet', 'https://www.example.com/default')
            ->addDeviceTarget(new LinkDeviceTarget()->setDevice('ios')->setTargetUrl('https://www.example.com/ios'))
            ->addDeviceTarget(new LinkDeviceTarget()->setDevice('android')->setTargetUrl('https://www.example.com/android'))
            ->addDeviceTarget(new LinkDeviceTarget()->setDevice('desktop')->setTargetUrl('https://www.example.com/desktop'))
            ->addLocalisation(new LinkLocalisation()->setLanguage('en')->setTargetUrl('https://www.example.com/en'));
        $this->em()->flush();

        $this->shortRequest(self::HOST, '/geraet', ['User-Agent' => self::IPHONE]);
        self::assertResponseRedirects('https://www.example.com/ios');

        $this->shortRequest(self::HOST, '/geraet', ['User-Agent' => self::ANDROID]);
        self::assertResponseRedirects('https://www.example.com/android');

        // Desktop device target beats the English header.
        $this->shortRequest(self::HOST, '/geraet', ['User-Agent' => self::CHROME, 'Accept-Language' => 'en-GB']);
        self::assertResponseRedirects('https://www.example.com/desktop');
    }

    public function testUnknownDeviceFallsBackToLanguageThenDefault(): void
    {
        $owner = $this->createUser('owner@example.com');
        $domain = $this->createDomain(self::HOST);
        $this->createLink($owner, $domain, 'ohnegeraet', 'https://www.example.com/default')
            ->addDeviceTarget(new LinkDeviceTarget()->setDevice('ios')->setTargetUrl('https://www.example.com/ios'))
            ->addLocalisation(new LinkLocalisation()->setLanguage('en')->setTargetUrl('https://www.example.com/en'));
        $this->em()->flush();

        // No desktop target: a desktop visitor falls through to the language.
        $this->shortRequest(self::HOST, '/ohnegeraet', ['User-Agent' => self::CHROME, 'Accept-Language' => 'en-GB']);
        self::assertResponseRedirects('https://www.example.com/en');

        // A bot counts as desktop, the language is unavailable -> the default target.
        $this->shortRequest(self::HOST, '/ohnegeraet', ['User-Agent' => self::BOT, 'Accept-Language' => 'fr']);
        self::assertResponseRedirects('https://www.example.com/default');
    }

    private function protectedLink(string $code, string $target = 'https://www.example.com/secret'): Link
    {
        $owner = $this->createUser('owner-'.$code.'@example.com');
        $domain = $this->domain();
        $link = $this->createLink($owner, $domain, $code, $target);
        $link->setPasswordHash(password_hash(self::UNLOCK_PASSWORD, \PASSWORD_DEFAULT));
        $this->em()->flush();

        return $link;
    }

    /** The one short host of a test: several links share it, so it is looked up before creating. */
    private function domain(): Domain
    {
        return $this->em()->getRepository(Domain::class)->findOneBy(['hostname' => self::HOST])
            ?? $this->createDomain(self::HOST);
    }

    /** Replaces the gate limiter with a fresh in-memory one so attempts do not bleed across tests. */
    private function useGate(int $limit): void
    {
        static::getContainer()->set('limiter.link_gate', new RateLimiterFactory(
            ['id' => 'test.link_gate', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        ));
    }

    /** @return array{code: string, expiry: int, mac: string} */
    private function form(string $code, string $ip = self::IP, string $userAgent = self::CHROME): array
    {
        $this->shortRequest(self::HOST, '/'.$code, ['User-Agent' => $userAgent], 'GET', ['REMOTE_ADDR' => $ip]);
        self::assertResponseStatusCodeSame(200);
        $crawler = $this->client->getCrawler();

        return [
            'code' => (string) $crawler->filter('input[name="code"]')->attr('value'),
            'expiry' => (int) $crawler->filter('input[name="expiry"]')->attr('value'),
            'mac' => (string) $crawler->filter('input[name="mac"]')->attr('value'),
        ];
    }

    /** @param array<string, string|int> $fields */
    private function postUnlock(string $code, array $fields, string $ip = self::IP, string $userAgent = self::CHROME): void
    {
        $this->client->request('POST', '/'.$code, $fields, [], [
            'HTTP_HOST' => self::HOST,
            'REMOTE_ADDR' => $ip,
            'HTTP_USER_AGENT' => $userAgent,
        ]);
    }

    private function assertNoClick(Link $link): void
    {
        $link = $this->fresh($link);
        self::assertSame(0, $link->getClickCount(), 'no click may be recorded without a successful unlock');
        self::assertCount(0, $this->em()->getRepository(Click::class)->findBy(['link' => $link]));
    }
}
