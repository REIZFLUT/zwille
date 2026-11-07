<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ApiToken;
use App\Entity\Link;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\TagRepository;
use App\Tracking\ClickRecorder;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class TagTest extends AppTestCase
{
    private const string CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    // --- Tag management page ---------------------------------------------

    public function testANewUserSeesAnEmptyTagPage(): void
    {
        $user = $this->createUser('user@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/tags');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty h2', 'Noch keine Tags');
    }

    public function testUserCreatesRenamesAndDeletesATag(): void
    {
        $user = $this->createUser('user@example.com');
        $this->client->loginUser($user);

        // Create through the form.
        $crawler = $this->client->request('GET', '/tags');
        $token = (string) $crawler->filter('form[action="/tags/new"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/tags/new', ['_token' => $token, 'name' => 'Kampagne']);
        self::assertResponseRedirects('/tags');

        $this->em()->clear();
        $tag = $this->tags()->findOneByOwnerName($user, 'Kampagne');
        self::assertNotNull($tag);
        self::assertSame('kampagne', $tag->getNormalizedName());

        // Rename inline.
        $crawler = $this->client->request('GET', '/tags');
        self::assertSame('Kampagne', $crawler->filter('form[action="/tags/'.$tag->getId().'"] input[name="name"]')->attr('value'));
        $renameToken = (string) $crawler->filter('form[action="/tags/'.$tag->getId().'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/tags/'.$tag->getId(), ['_token' => $renameToken, 'name' => 'Herbst']);
        self::assertResponseRedirects('/tags');

        $this->em()->clear();
        self::assertSame('Herbst', $this->tags()->findOneByOwnerName($user, 'Herbst')?->getName());

        // Delete (the id is stable across the rename).
        $crawler = $this->client->request('GET', '/tags');
        $deleteToken = (string) $crawler->filter('form[action="/tags/'.$tag->getId().'/delete"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/tags/'.$tag->getId().'/delete', ['_token' => $deleteToken]);
        self::assertResponseRedirects('/tags');

        $this->em()->clear();
        self::assertNull($this->tags()->findOneByOwnerName($user, 'Herbst'));
    }

    public function testDuplicateTagNameIsRejected(): void
    {
        $user = $this->createUser('user@example.com');
        $this->createTag($user, 'Sommer');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/tags');
        $token = (string) $crawler->filter('form[action="/tags/new"] input[name="_token"]')->attr('value');
        // Different case must collide too: the normalised name is the key.
        $this->client->request('POST', '/tags/new', ['_token' => $token, 'name' => ' sommer ']);
        self::assertResponseRedirects('/tags');

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('schon', $crawler->filter('.flash')->text());
        $this->em()->clear();
        self::assertCount(1, $this->tags()->findByOwner($user));
    }

    // --- Link ⇄ tag through the UI ---------------------------------------

    public function testCreatingALinkWithTagsAndNotes(): void
    {
        $user = $this->createUser('user@example.com');
        $domain = $this->createDomain('go.example.com', $user);
        $tag = $this->createTag($user, 'Kampagne');
        $this->client->loginUser($user);

        $this->client->request('GET', '/links/new');
        $this->client->submitForm('Link anlegen', [
            'link[targetUrl]' => 'https://www.example.com/aktion',
            'link[domain]' => (string) $domain->getId(),
            'link[code]' => 'aktion',
            'link[tags]' => [(string) $tag->getId()],
            'link[notes]' => 'Nur für das Team.',
        ], serverParameters: ['HTTP_ORIGIN' => 'http://'.self::ADMIN_HOST]);
        self::assertResponseRedirects();

        $this->em()->clear();
        $link = $this->em()->getRepository(Link::class)->findOneBy(['code' => 'aktion']);
        self::assertNotNull($link);
        self::assertSame('Nur für das Team.', $link->getNotes());
        self::assertSame([$tag->getId()], $link->getTags()->map(static fn (Tag $t): ?int => $t->getId())->toArray());
    }

    public function testListFiltersByTagAndKeepsTheFilterOnSortAndBulk(): void
    {
        $user = $this->createUser('user@example.com');
        $domain = $this->createDomain('go.example.com', $user);
        $tag = $this->createTag($user, 'Kampagne');
        $tagged = $this->createLink($user, $domain, 'mit-tag');
        $this->createLink($user, $domain, 'ohne-tag');
        $tagged->addTag($tag);
        $this->em()->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/links?tag='.$tag->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(['mit-tag'], $this->codes($crawler), 'only the tagged link is listed');

        // The sort headers and the bulk form keep the tag filter.
        self::assertStringContainsString('tag='.$tag->getId(), (string) $crawler->filter('th a.sort-link')->first()->attr('href'));
        self::assertSame((string) $tag->getId(), $crawler->filter('#bulk-form input[name="return[tag]"]')->attr('value'));
    }

    public function testBulkAddsAndRemovesATagOnSeveralLinks(): void
    {
        $user = $this->createUser('user@example.com');
        $domain = $this->createDomain('go.example.com', $user);
        $tag = $this->createTag($user, 'Kampagne');
        $first = $this->createLink($user, $domain, 'eins');
        $second = $this->createLink($user, $domain, 'zwei');
        $this->client->loginUser($user);

        $this->bulk($this->bulkToken(), 'tag_add', [(int) $first->getId(), (int) $second->getId()], (int) $tag->getId());
        self::assertResponseRedirects('/links');
        self::assertSame([$tag->getId()], $this->tagIds($first));
        self::assertSame([$tag->getId()], $this->tagIds($second));

        // Removing the tag again detaches it from both links.
        $this->bulk($this->bulkToken(), 'tag_remove', [(int) $first->getId(), (int) $second->getId()], (int) $tag->getId());
        self::assertResponseRedirects('/links');
        self::assertSame([], $this->tagIds($first));
        self::assertSame([], $this->tagIds($second));
    }

    // --- Ownership --------------------------------------------------------

    public function testAForeignTagIsNotListedAndCannotBeRenamedOrDeleted(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $foreign = $this->createTag($alice, 'Geheim');
        $this->client->loginUser($bob);

        $crawler = $this->client->request('GET', '/tags');
        self::assertSelectorTextNotContains('body', 'Geheim');

        $this->client->request('POST', '/tags/'.$foreign->getId(), ['name' => 'Geklaut']);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/tags/'.$foreign->getId().'/delete');
        self::assertResponseStatusCodeSame(404);

        $this->em()->clear();
        self::assertSame('Geheim', $this->tags()->findOneByOwnerName($alice, 'Geheim')?->getName(), 'the foreign tag is untouched');
    }

    public function testAForeignTagCannotBeAppliedInABulkAction(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $domain = $this->createDomain('go.example.com', $alice, $bob);
        $foreign = $this->createTag($alice, 'Geheim');
        $link = $this->createLink($bob, $domain, 'meins');
        $this->client->loginUser($bob);

        $this->bulk($this->bulkToken(), 'tag_add', [(int) $link->getId()], (int) $foreign->getId());
        self::assertResponseRedirects('/links');
        self::assertSame([], $this->tagIds($link), 'a foreign tag is never attached');
    }

    // --- Notes ------------------------------------------------------------

    public function testTheInternalNoteIsSearchable(): void
    {
        $user = $this->createUser('user@example.com');
        $domain = $this->createDomain('go.example.com', $user);
        $link = $this->createLink($user, $domain, 'notiz-link');
        $link->setNotes('geheimnotiz');
        $this->em()->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/links?q=geheimnotiz');
        self::assertResponseIsSuccessful();
        self::assertSame(['notiz-link'], $this->codes($crawler));
    }

    public function testTheTagPageShowsLinkCountAndClicksAfterAClick(): void
    {
        $user = $this->createUser('user@example.com');
        $domain = $this->createDomain('go.example.com', $user);
        $tag = $this->createTag($user, 'Kampagne');
        $link = $this->createLink($user, $domain, 'klick', 'https://www.example.com/klick');
        $link->addTag($tag);
        $this->em()->flush();

        // A real click through the production recorder updates link.click_count.
        $recorder = static::getContainer()->get(ClickRecorder::class);
        $request = Request::create('/klick', 'GET', server: ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => self::CHROME]);
        $recorder->record($request, (int) $link->getId(), new \DateTimeImmutable());
        $this->em()->clear();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/tags');
        self::assertResponseIsSuccessful();

        $nums = $crawler->filter('tbody tr')->first()->filter('td.num');
        self::assertSame('1', trim($nums->eq(0)->text()), 'one link carries the tag');
        self::assertSame('1', trim($nums->eq(1)->text()), 'the click is summed into the tag total');
    }

    public function testDeletingATagKeepsItsLinks(): void
    {
        $user = $this->createUser('user@example.com');
        $domain = $this->createDomain('go.example.com', $user);
        $tag = $this->createTag($user, 'Kampagne');
        $link = $this->createLink($user, $domain, 'bleibt');
        $link->addTag($tag);
        $this->em()->flush();
        $linkId = (int) $link->getId();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/tags');
        $token = (string) $crawler->filter('form[action="/tags/'.$tag->getId().'/delete"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/tags/'.$tag->getId().'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/tags');

        $this->em()->clear();
        self::assertNull($this->tags()->find((int) $tag->getId()));
        $fresh = $this->em()->find(Link::class, $linkId);
        self::assertNotNull($fresh, 'the link survives');
        self::assertSame([], $this->tagIds($fresh));
    }

    // --- API --------------------------------------------------------------

    public function testApiAcceptsAndReturnsTagsAndCreatesUnknownOnes(): void
    {
        $user = $this->createUser('api@example.com');
        $this->createDomain('go.example.com', $user);
        $token = $this->token($user);

        $this->api('POST', '/api/v1/links', $token, [
            'target_url' => 'https://www.example.com/kampagne',
            'code' => 'kampagne',
            'tags' => ['Kampagne', 'Herbst'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->json()['data'];
        self::assertSame(['Kampagne', 'Herbst'], $created['tags']);

        $this->em()->clear();
        self::assertNotNull($this->tags()->findOneByOwnerName($user, 'Kampagne'));
        self::assertNotNull($this->tags()->findOneByOwnerName($user, 'Herbst'));

        $this->api('PATCH', '/api/v1/links/'.$created['id'], $token, ['tags' => ['Neu']]);
        self::assertResponseIsSuccessful();
        self::assertSame(['Neu'], $this->json()['data']['tags']);

        // The list carries the tags too.
        $this->api('GET', '/api/v1/links', $token);
        self::assertSame(['Neu'], $this->json()['data'][0]['tags']);
    }

    public function testApiNeverReturnsOrAcceptsNotes(): void
    {
        $user = $this->createUser('api@example.com');
        $domain = $this->createDomain('go.example.com', $user);
        $link = $this->createLink($user, $domain, 'intern');
        $link->setNotes('streng geheim');
        $this->em()->flush();
        $token = $this->token($user);

        // Rejected as input, whether on create or update.
        $this->api('POST', '/api/v1/links', $token, ['target_url' => 'https://www.example.com/neu', 'notes' => 'x']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('notes', $this->json()['violations'][0]['field']);

        $this->api('PATCH', '/api/v1/links/'.$link->getId(), $token, ['notes' => 'x']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('notes', $this->json()['violations'][0]['field']);

        // Never serialised on read or list.
        $this->api('GET', '/api/v1/links/'.$link->getId(), $token);
        self::assertArrayNotHasKey('notes', $this->json()['data']);

        $this->api('GET', '/api/v1/links', $token);
        self::assertArrayNotHasKey('notes', $this->json()['data'][0]);
    }

    // --- Helpers ----------------------------------------------------------

    private function createTag(User $owner, string $name): Tag
    {
        $tag = new Tag($owner, $name);
        $this->em()->persist($tag);
        $this->em()->flush();

        return $tag;
    }

    private function tags(): TagRepository
    {
        return static::getContainer()->get(TagRepository::class);
    }

    /** @return list<int> tag ids of the freshly loaded link */
    private function tagIds(Link $link): array
    {
        $this->em()->clear();
        $fresh = $this->em()->find(Link::class, $link->getId());
        self::assertNotNull($fresh);

        return array_values($fresh->getTags()->map(static fn (Tag $t): int => (int) $t->getId())->toArray());
    }

    /** @return list<string> codes in table row order */
    private function codes(Crawler $crawler): array
    {
        $codes = [];
        foreach ($crawler->filter('tbody .link-cell .short') as $node) {
            $text = trim($node->textContent);
            $codes[] = substr($text, strrpos($text, '/') + 1);
        }

        return $codes;
    }

    /** Reads the CSRF token from the rendered bulk form. */
    private function bulkToken(): string
    {
        $crawler = $this->client->request('GET', '/links');
        self::assertSelectorExists('#bulk-form');

        return (string) $crawler->filter('#bulk-form input[name="_token"]')->attr('value');
    }

    /** @param list<int> $ids */
    private function bulk(string $token, string $action, array $ids, int $tagId): void
    {
        $this->client->request('POST', '/links/bulk', [
            '_token' => $token,
            'action' => $action,
            'tag' => (string) $tagId,
            'ids' => array_map(static fn (int $id): string => (string) $id, $ids),
        ], server: ['HTTP_ORIGIN' => 'http://'.self::ADMIN_HOST]);
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
