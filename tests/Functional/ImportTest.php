<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Domain;
use App\Entity\Link;
use App\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImportTest extends AppTestCase
{
    private function upload(string $content, string $name = 'links.csv'): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'zwille_csv_');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    /** Fetches the CSRF token from the import form of the (already authenticated) admin. */
    private function token(): string
    {
        $this->client->request('GET', '/admin/import');

        return (string) $this->client->getCrawler()
            ->filter('form[action="/admin/import"] input[name="_token"]')
            ->attr('value');
    }

    /**
     * @param array<string, string> $params
     */
    private function post(array $params, string $csv): void
    {
        $this->client->request('POST', '/admin/import', $params, ['file' => $this->upload($csv)]);
    }

    /** @return array<string, string> */
    private function params(User $owner, Domain $domain, string $action): array
    {
        return [
            'owner' => (string) $owner->getId(),
            'domain' => (string) $domain->getId(),
            'skip_duplicates' => '1',
            'action' => $action,
            '_token' => $this->token(),
        ];
    }

    public function testNonAdminIsDenied(): void
    {
        $this->client->loginUser($this->createUser('user@example.com'));

        $this->client->request('GET', '/admin/import');
        self::assertResponseStatusCodeSame(403);

        $this->post(['owner' => '1', 'domain' => '1', 'action' => 'preview', '_token' => 'x'], "code,target_url\n");
        self::assertResponseStatusCodeSame(403);
    }

    public function testImportDoesNotFetchPageTitles(): void
    {
        $admin = $this->createUser('root@example.com', admin: true);
        $domain = $this->createDomain('go.example.com', $admin);
        $this->client->loginUser($admin);

        // Title lookup is on by default and the mocked HTTP client would answer with
        // MockResponseFactory::TITLE: an imported row must keep its empty title.
        $this->post($this->params($admin, $domain, 'import'), "code,target_url\nohne,https://93.184.216.34/a\n");
        self::assertResponseIsSuccessful();

        $link = $this->em()->getRepository(Link::class)->findOneBy(['code' => 'ohne']);
        self::assertNotNull($link);
        self::assertNull($link->getTitle());
    }

    public function testPreviewCountsRowsAndWritesNothing(): void
    {
        $admin = $this->createUser('root@example.com', admin: true);
        $domain = $this->createDomain('go.example.com', $admin);
        $this->client->loginUser($admin);

        $csv = "code,target_url\n"
            ."alpha,https://www.example.com/a\n"
            ."alpha,https://www.example.com/b\n"
            ."beta,javascript:alert(1)\n";

        $this->post($this->params($admin, $domain, 'preview'), $csv);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.import-columns', 'code');
        self::assertSelectorTextContains('.import-columns', 'target_url');
        self::assertSelectorTextContains('.import-summary', 'Gültig: 1');
        self::assertSelectorTextContains('.import-summary', 'Ungültig: 1');
        self::assertSelectorTextContains('.import-summary', 'Dubletten: 1');
        self::assertSame(0, $this->em()->getRepository(Link::class)->count([]), 'a preview must not write');
    }

    public function testImportCreatesLinksSkipsDuplicateReportsInvalid(): void
    {
        $admin = $this->createUser('root@example.com', admin: true);
        $domain = $this->createDomain('go.example.com', $admin);
        $this->client->loginUser($admin);

        $csv = "code,target_url,title\n"
            ."first,https://www.example.com/a,Erste\n"
            ."second,https://www.example.com/b,Zweite\n"
            ."first,https://www.example.com/c,Dublette\n"
            ."bad,javascript:alert(1),Kaputt\n";

        $this->post($this->params($admin, $domain, 'import'), $csv);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.import-result', 'Angelegt: 2');
        self::assertSelectorTextContains('.import-result', 'Übersprungen: 1');
        self::assertSelectorTextContains('.import-result', 'Fehler: 1');
        // Rows are listed with their physical line number (header is line 1).
        self::assertSelectorExists('tr[data-line="5"].row-error');
        self::assertSelectorExists('tr[data-line="4"].row-duplicate');

        $this->em()->clear();
        $links = $this->em()->getRepository(Link::class)->findAll();
        self::assertCount(2, $links);
        $codes = array_map(static fn (Link $link): string => $link->getCode(), $links);
        self::assertContains('first', $codes);
        self::assertContains('second', $codes);
        self::assertNotContains('bad', $codes);
    }

    public function testYourlsColumnsAreRecognised(): void
    {
        $admin = $this->createUser('root@example.com', admin: true);
        $domain = $this->createDomain('go.example.com', $admin);
        $this->client->loginUser($admin);

        $csv = "url,keyword,title\nhttps://www.example.com/shop,shop,Shop\n";

        $this->post($this->params($admin, $domain, 'import'), $csv);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.import-columns', 'target_url');
        self::assertSelectorTextContains('.import-columns', 'code');
        $link = $this->em()->getRepository(Link::class)->findOneBy(['code' => 'shop']);
        self::assertNotNull($link);
        self::assertSame('https://www.example.com/shop', $link->getTargetUrl());
        self::assertSame('Shop', $link->getTitle());
    }

    public function testSemicolonSeparatedFileWithBomWorks(): void
    {
        $admin = $this->createUser('root@example.com', admin: true);
        $domain = $this->createDomain('go.example.com', $admin);
        $this->client->loginUser($admin);

        $csv = "\xEF\xBB\xBFcode;target_url;title\nbom1;https://www.example.com/x;BOM\n";

        $this->post($this->params($admin, $domain, 'preview'), $csv);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.import-columns', 'target_url');
        self::assertSelectorTextContains('.import-summary', 'Gültig: 1');

        $this->post($this->params($admin, $domain, 'import'), $csv);
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->em()->getRepository(Link::class)->findOneBy(['code' => 'bom1']));
    }

    public function testEmptyCodeGetsAGeneratedOne(): void
    {
        $admin = $this->createUser('root@example.com', admin: true);
        $domain = $this->createDomain('go.example.com', $admin);
        $this->client->loginUser($admin);

        $csv = "code,target_url\n,https://www.example.com/generated\n";

        $this->post($this->params($admin, $domain, 'import'), $csv);

        self::assertResponseIsSuccessful();
        $link = $this->em()->getRepository(Link::class)->findOneBy(['targetUrl' => 'https://www.example.com/generated']);
        self::assertNotNull($link);
        self::assertMatchesRegularExpression('/^[23456789abcdefghjkmnpqrstuvwxyz]{6}$/', $link->getCode());
    }

    public function testImportForAnotherOwnerAssignsTheirLinks(): void
    {
        $admin = $this->createUser('root@example.com', admin: true);
        $bob = $this->createUser('bob@example.com');
        $domain = $this->createDomain('go.example.com', $bob);
        $this->client->loginUser($admin);

        $csv = "code,target_url\nshop,https://www.example.com/shop\n";

        $this->post($this->params($bob, $domain, 'import'), $csv);

        self::assertResponseIsSuccessful();
        $link = $this->em()->getRepository(Link::class)->findOneBy(['code' => 'shop']);
        self::assertNotNull($link);
        self::assertSame('bob@example.com', $link->getOwner()->getEmail());
    }

    public function testLargeImportSurvivesTheClearBetweenBatches(): void
    {
        $admin = $this->createUser('root@example.com', admin: true);
        $domain = $this->createDomain('go.example.com', $admin);
        $this->client->loginUser($admin);

        $csv = "code,target_url\n";
        for ($i = 1; $i <= 205; ++$i) {
            $csv .= 'r'.$i.',https://www.example.com/'.$i."\n";
        }

        $this->post($this->params($admin, $domain, 'import'), $csv);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.import-result', 'Angelegt: 205');
        $this->em()->clear();
        self::assertSame(205, $this->em()->getRepository(Link::class)->count([]));
        // The row after the clear() (code index 201) must still be owned and persisted.
        $link = $this->em()->getRepository(Link::class)->findOneBy(['code' => 'r201']);
        self::assertNotNull($link);
        self::assertSame('root@example.com', $link->getOwner()->getEmail());
    }
}
