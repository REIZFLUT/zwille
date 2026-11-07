<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ApiToken;
use App\Entity\Click;
use App\Entity\Domain;
use App\Entity\Link;
use App\Entity\User;
use App\Security\ApiScope;
use App\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Agent tokens ("emsa_…"): never admin, only their scopes, optional limits to domains,
 * target hosts and client addresses, an extra write budget, revocation and a kill switch.
 */
final class AgentTokenTest extends AppTestCase
{
    private const array ALL_SCOPES = ApiScope::ALL;

    public function testAgentTokenOfAnAdminActsLikeANormalUser(): void
    {
        // The test allowlist is empty, so a personal admin token would see everything.
        $admin = $this->createUser('root@example.com', admin: true);
        $other = $this->createUser('other@example.com');
        $domain = $this->createDomain('go.example.com', $admin, $other);
        $privateDomain = $this->createDomain('privat.example.com');
        $this->createLink($admin, $domain, 'meins');
        $foreign = $this->createLink($other, $domain, 'fremd');
        $this->em()->persist(new Click($foreign, new \DateTimeImmutable('-1 hour'), 'besucher-x'));
        $this->em()->flush();

        $personal = $this->personalToken($admin);
        $this->api('GET', '/api/v1/links', $personal);
        self::assertSame(2, $this->json()['meta']['total'], 'the personal admin token still sees every link');

        $agent = $this->agentToken($admin, self::ALL_SCOPES);
        $this->api('GET', '/api/v1/links', $agent);
        self::assertResponseIsSuccessful();
        self::assertSame(['meins'], array_column($this->json()['data'], 'code'));

        $this->api('GET', '/api/v1/links/'.$foreign->getId(), $agent);
        self::assertResponseStatusCodeSame(403);

        $this->api('DELETE', '/api/v1/links/'.$foreign->getId(), $agent);
        self::assertResponseStatusCodeSame(403);

        $this->api('GET', '/api/v1/domains', $agent);
        self::assertSame(['go.example.com'], array_column($this->json()['data'], 'hostname'), 'only the assigned domains, not every active one');

        $this->api('POST', '/api/v1/links', $agent, ['target_url' => 'https://www.example.com/x', 'domain' => $privateDomain->getHostname()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('domain', $this->json()['violations'][0]['field']);

        $this->api('GET', '/api/v1/stats?days=7', $agent);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->json()['data']['totals']['clicks'], 'account statistics cover only the own links');
    }

    public function testScopesAreEnforced(): void
    {
        $user = $this->createUser('agent@example.com');
        $domain = $this->createDomain('go.example.com', $user);
        $link = $this->createLink($user, $domain, 'lesen');
        $readOnly = $this->agentToken($user, ApiScope::AGENT_DEFAULT);

        $this->api('GET', '/api/v1/links/'.$link->getId(), $readOnly);
        self::assertResponseIsSuccessful();

        $this->api('POST', '/api/v1/links', $readOnly, ['target_url' => 'https://www.example.com/neu']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('scope_missing', $this->json()['error']);
        self::assertSame(ApiScope::LINKS_WRITE, $this->json()['required_scope']);

        $this->api('PATCH', '/api/v1/links/'.$link->getId(), $readOnly, ['title' => 'nein']);
        self::assertResponseStatusCodeSame(403);

        $writer = $this->agentToken($user, [ApiScope::LINKS_READ, ApiScope::LINKS_WRITE]);
        $this->api('PATCH', '/api/v1/links/'.$link->getId(), $writer, ['title' => 'ja']);
        self::assertResponseIsSuccessful();

        $this->api('DELETE', '/api/v1/links/'.$link->getId(), $writer);
        self::assertResponseStatusCodeSame(403, 'writing does not include deleting');
        self::assertSame(ApiScope::LINKS_DELETE, $this->json()['required_scope']);

        $this->api('GET', '/api/v1/stats', $writer);
        self::assertResponseStatusCodeSame(403);
        $this->api('GET', '/api/v1/domains', $writer);
        self::assertResponseStatusCodeSame(403);
    }

    public function testRevokedTokenAndKillSwitchAreRefused(): void
    {
        $user = $this->createUser('agent@example.com');
        $this->createDomain('go.example.com', $user);
        [$token, $plain] = ApiToken::issueForAgent($user, 'bot', 30, ApiScope::AGENT_DEFAULT);
        $this->em()->persist($token);
        $this->em()->flush();
        $personal = $this->personalToken($user);

        $this->api('GET', '/api/v1/domains', $plain);
        self::assertResponseIsSuccessful();

        static::getContainer()->get(Settings::class)->save([Settings::AGENT_ACCESS => false]);
        $this->api('GET', '/api/v1/domains', $plain);
        self::assertResponseStatusCodeSame(401);
        $this->api('GET', '/api/v1/domains', $personal);
        self::assertResponseIsSuccessful('the kill switch leaves personal tokens alone');

        static::getContainer()->get(Settings::class)->save([Settings::AGENT_ACCESS => true]);
        $this->api('GET', '/api/v1/domains', $plain);
        self::assertResponseIsSuccessful();

        $this->fresh($token)->revoke();
        $this->em()->flush();
        $this->api('GET', '/api/v1/domains', $plain);
        self::assertResponseStatusCodeSame(401);
    }

    public function testClientAddressLimit(): void
    {
        $user = $this->createUser('agent@example.com');
        $this->createDomain('go.example.com', $user);
        [$token, $plain] = ApiToken::issueForAgent($user, 'bot', 7, ApiScope::AGENT_DEFAULT, allowedIps: ['198.51.100.0/24']);
        $this->em()->persist($token);
        $this->em()->flush();

        // The test client comes from 203.0.113.10.
        $this->api('GET', '/api/v1/domains', $plain);
        self::assertResponseStatusCodeSame(401);

        $this->api('GET', '/api/v1/domains', $plain, server: ['REMOTE_ADDR' => '198.51.100.23']);
        self::assertResponseIsSuccessful();
    }

    public function testDomainLimit(): void
    {
        $user = $this->createUser('agent@example.com');
        $allowed = $this->createDomain('go.example.com', $user);
        $other = $this->createDomain('jobs.example.com', $user);
        $this->createLink($user, $allowed, 'hier');
        $elsewhere = $this->createLink($user, $other, 'dort');
        $plain = $this->agentToken($user, self::ALL_SCOPES, [$allowed]);

        $this->api('GET', '/api/v1/domains', $plain);
        self::assertSame(['go.example.com'], array_column($this->json()['data'], 'hostname'));

        $this->api('GET', '/api/v1/links', $plain);
        self::assertSame(['hier'], array_column($this->json()['data'], 'code'));

        $this->api('GET', '/api/v1/links/'.$elsewhere->getId(), $plain);
        self::assertResponseStatusCodeSame(403);

        $this->api('POST', '/api/v1/links', $plain, ['target_url' => 'https://www.example.com/a', 'domain' => 'jobs.example.com']);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', '/api/v1/links', $plain, ['target_url' => 'https://www.example.com/b']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('go.example.com', $this->json()['data']['domain'], 'the default domain is one the token may use');

        $this->api('GET', '/api/v1/stats', $plain);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('scope_restricted', $this->json()['error']);

        $this->api('GET', '/api/v1/expand?url=https://jobs.example.com/dort', $plain);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTargetHostLimit(): void
    {
        $user = $this->createUser('agent@example.com');
        $this->createDomain('go.example.com', $user);
        $plain = $this->agentToken($user, self::ALL_SCOPES, targetHosts: ['*.Example.com']);

        $this->api('POST', '/api/v1/links', $plain, ['target_url' => 'https://phish.example.net/login']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('target_url', $this->json()['violations'][0]['field']);
        self::assertStringContainsString('phish.example.net', $this->json()['violations'][0]['message']);

        $this->api('POST', '/api/v1/links', $plain, ['target_url' => 'https://shop.example.com/', 'code' => 'shop']);
        self::assertResponseStatusCodeSame(201, 'subdomains of an allowed host are allowed');
        $id = $this->json()['data']['id'];

        $this->api('PATCH', '/api/v1/links/'.$id, $plain, ['localisations' => ['en' => 'https://evil.example.org/']]);
        self::assertResponseStatusCodeSame(422, 'language-specific targets are checked too');

        $this->api('PATCH', '/api/v1/links/'.$id, $plain, ['target_url' => 'https://example.com.evil.test/']);
        self::assertResponseStatusCodeSame(422, 'a suffix trick is not a subdomain');
    }

    public function testWriteBudgetPerToken(): void
    {
        $user = $this->createUser('agent@example.com');
        $domain = $this->createDomain('go.example.com', $user);
        $links = [
            $this->createLink($user, $domain, 'a'),
            $this->createLink($user, $domain, 'b'),
            $this->createLink($user, $domain, 'c'),
        ];
        $plain = $this->agentToken($user, self::ALL_SCOPES);

        // Tiny limits with their own storage: the test cache is reset between requests.
        static::getContainer()->set('limiter.agent_write', $this->limiter('agent_write', 3));
        static::getContainer()->set('limiter.agent_delete_daily', $this->limiter('agent_delete_daily', 1));

        $this->api('DELETE', '/api/v1/links/'.$links[0]->getId(), $plain);
        self::assertResponseStatusCodeSame(204);
        $this->api('DELETE', '/api/v1/links/'.$links[1]->getId(), $plain);
        self::assertResponseStatusCodeSame(429, 'one deletion per day in this test');
        self::assertSame('rate_limited', $this->json()['error']);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
        self::assertNotNull($this->em()->find(Link::class, $links[1]->getId()), 'a refused deletion deletes nothing');

        // Both deletions counted as writes, the refused one included: one write is left.
        $this->api('PATCH', '/api/v1/links/'.$links[2]->getId(), $plain, ['title' => 'eins']);
        self::assertResponseIsSuccessful();
        $this->api('PATCH', '/api/v1/links/'.$links[2]->getId(), $plain, ['title' => 'zwei']);
        self::assertResponseStatusCodeSame(429, 'three writes per minute in this test');

        $this->api('GET', '/api/v1/links/'.$links[2]->getId(), $plain);
        self::assertResponseIsSuccessful('reading is not part of the write budget');
    }

    public function testIssuingRulesOfTheEntity(): void
    {
        $user = new User()->setEmail('x@example.com');

        [$token, $plain] = ApiToken::issueForAgent($user, 'bot', 90, ['links:read', 'admin:everything']);
        self::assertStringStartsWith(ApiToken::AGENT_PREFIX, $plain);
        self::assertTrue($token->isAgent());
        self::assertSame([ApiScope::LINKS_READ], $token->getScopes(), 'unknown scopes are dropped');
        self::assertNotNull($token->getExpiresAt());
        self::assertTrue($token->hasScope(ApiScope::LINKS_READ));
        self::assertFalse($token->hasScope(ApiScope::LINKS_WRITE));

        [$personal] = ApiToken::issue($user, 'crm');
        self::assertFalse($personal->isAgent());
        self::assertSame(ApiScope::ALL, $personal->getScopes());

        try {
            ApiToken::issueForAgent($user, 'bot', 365, ApiScope::AGENT_DEFAULT);
            self::fail('an agent token must not live longer than 90 days');
        } catch (\InvalidArgumentException) {
        }
        try {
            ApiToken::issueForAgent($user, 'bot', 30, ['nonsense']);
            self::fail('an agent token needs a scope');
        } catch (\InvalidArgumentException) {
        }
    }

    public function testProfilePageIssuesAndRevokesAgentTokens(): void
    {
        $user = $this->createUser('agent@example.com');
        $domain = $this->createDomain('go.example.com', $user);
        $this->client->loginUser($user);
        $this->confirm();

        $crawler = $this->client->request('GET', '/profile/tokens');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name=agent_token]')->form();
        $form['agent_token[name]'] = 'Marketing-Bot';
        $days = $form->get('agent_token[days]');
        self::assertInstanceOf(ChoiceFormField::class, $days);
        $days->select('7');
        $domainBoxes = $form->get('agent_token[domains]');
        self::assertIsArray($domainBoxes);
        $firstDomain = $domainBoxes[0] ?? null;
        self::assertInstanceOf(ChoiceFormField::class, $firstDomain);
        $firstDomain->tick();
        $form['agent_token[targetHosts]'] = "example.com\nnot a host!";
        $form['agent_token[allowedIps]'] = '203.0.113.0/24';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.errors', 'not a host!');

        $form = $this->client->getCrawler()->filter('form[name=agent_token]')->form();
        $form['agent_token[targetHosts]'] = 'example.com';
        $this->client->submit($form);
        self::assertResponseIsSuccessful();
        $plain = (string) $this->client->getCrawler()->filter('#new-token')->attr('value');
        self::assertStringStartsWith(ApiToken::AGENT_PREFIX, $plain);

        $token = $this->em()->getRepository(ApiToken::class)->findOneByPlainToken($plain);
        self::assertNotNull($token);
        self::assertSame('Marketing-Bot', $token->getName());
        self::assertSame(ApiScope::AGENT_DEFAULT, $token->getScopes());
        self::assertSame(['example.com'], $token->getTargetHosts());
        self::assertSame(['203.0.113.0/24'], $token->getAllowedIps());
        self::assertSame([$domain->getId()], $token->getDomains()->map(static fn (Domain $d): ?int => $d->getId())->getValues());
        self::assertLessThanOrEqual(new \DateTimeImmutable('+7 days +1 minute'), $token->getExpiresAt());

        $this->api('GET', '/api/v1/links', $plain);
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/profile/tokens');
        $this->client->submit($crawler->filter('form[action$="/tokens/'.$token->getId().'/delete"]')->form());
        self::assertResponseRedirects('/profile/tokens');
        $revoked = $this->fresh($token);
        self::assertTrue($revoked->isRevoked(), 'an agent token is kept as revoked');

        $this->api('GET', '/api/v1/links', $plain);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminOverviewAndRevokeAll(): void
    {
        $admin = $this->createUser('root@example.com', admin: true);
        $user = $this->createUser('agent@example.com');
        $this->createDomain('go.example.com', $user);
        $first = $this->agentToken($user, ApiScope::AGENT_DEFAULT);
        $second = $this->agentToken($admin, ApiScope::AGENT_DEFAULT);
        $personal = $this->personalToken($user);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/agent-tokens');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'agent@example.com');
        self::assertCount(2, $crawler->filter('tbody tr'));

        $this->client->submit($crawler->filter('form[action$="/admin/agent-tokens/revoke-all"]')->form());
        self::assertResponseRedirects('/admin/agent-tokens');

        foreach ([$first, $second] as $plain) {
            $this->api('GET', '/api/v1/links', $plain);
            self::assertResponseStatusCodeSame(401);
        }
        $this->api('GET', '/api/v1/links', $personal);
        self::assertResponseIsSuccessful('revoking agents leaves personal tokens alone');
    }

    public function testRevokeCommand(): void
    {
        $user = $this->createUser('agent@example.com');
        $this->createDomain('go.example.com', $user);
        [$token, $plain] = ApiToken::issueForAgent($user, 'cli-bot', 30, ApiScope::AGENT_DEFAULT);
        $this->em()->persist($token);
        $this->em()->flush();
        $other = $this->agentToken($user, ApiScope::AGENT_DEFAULT);
        $personal = $this->personalToken($user);

        $command = new CommandTester(new Application(static::$kernel ?? self::bootKernel())->find('app:token:revoke'));
        $command->execute([]);
        $command->assertCommandIsSuccessful();
        self::assertStringContainsString('cli-bot', $command->getDisplay());

        $command->execute(['id' => (string) $token->getId()]);
        $command->assertCommandIsSuccessful();
        $this->api('GET', '/api/v1/domains', $plain);
        self::assertResponseStatusCodeSame(401);
        $this->api('GET', '/api/v1/domains', $other);
        self::assertResponseIsSuccessful();

        $command->execute(['--all-agents' => true]);
        $command->assertCommandIsSuccessful();
        $this->api('GET', '/api/v1/domains', $other);
        self::assertResponseStatusCodeSame(401);
        $this->api('GET', '/api/v1/domains', $personal);
        self::assertResponseIsSuccessful();

        self::assertSame(1, $command->execute(['id' => '999999']));
    }

    public function testOrdinaryUserCannotOpenTheAdminOverview(): void
    {
        $this->client->loginUser($this->createUser('agent@example.com'));
        $this->client->request('GET', '/admin/agent-tokens');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param list<string> $scopes
     * @param list<Domain> $domains
     * @param list<string> $targetHosts
     */
    private function agentToken(User $user, array $scopes, array $domains = [], array $targetHosts = []): string
    {
        // Fixtures may be detached by an earlier request (the kernel clears the EntityManager).
        $user = $this->em()->find(User::class, $user->getId()) ?? $user;
        $domains = array_map(fn (Domain $d): Domain => $this->em()->find(Domain::class, $d->getId()) ?? $d, $domains);
        [$token, $plain] = ApiToken::issueForAgent($user, 'agent', 30, $scopes, $domains, $targetHosts);
        $this->em()->persist($token);
        $this->em()->flush();

        return $plain;
    }

    private function personalToken(User $user): string
    {
        $user = $this->em()->find(User::class, $user->getId()) ?? $user;
        [$token, $plain] = ApiToken::issue($user, 'personal');
        $this->em()->persist($token);
        $this->em()->flush();

        return $plain;
    }

    private function limiter(string $id, int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => 'test.'.$id, 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 day'], new InMemoryStorage());
    }

    private function confirm(): void
    {
        $this->client->request('GET', '/profile/confirm?target=tokens');
        $token = $this->client->getCrawler()->filter('form[action="/profile/confirm"] input[name=_token]')->attr('value');
        $this->client->request('POST', '/profile/confirm', ['password' => self::PASSWORD, 'target' => 'tokens', '_token' => $token]);
        self::assertResponseRedirects();
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string>            $server
     */
    private function api(string $method, string $uri, string $token, array|string|null $body = null, array $server = []): void
    {
        $this->client->request($method, $uri, server: $server + [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: \is_array($body) ? json_encode($body, \JSON_THROW_ON_ERROR) : $body);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
