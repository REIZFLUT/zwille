<?php

declare(strict_types=1);

namespace App\Demo;

use App\Audit\AuditLogger;
use App\Entity\AuditEntry;
use App\Entity\Click;
use App\Entity\Domain;
use App\Entity\Link;
use App\Entity\LinkDeviceTarget;
use App\Entity\LinkLocalisation;
use App\Entity\Tag;
use App\Entity\User;
use App\Link\LinkPassword;
use Doctrine\ORM\EntityManagerInterface;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fills an empty installation with a believable demo: the fictional company "Nordlicht",
 * three users, three short domains under *.localhost (browsers resolve those to 127.0.0.1,
 * so the short links work locally without a hosts entry), fourteen links covering every
 * feature (language and device targets, time window, click limit, password, inactive) and
 * a few thousand clicks over the last six weeks for the statistics.
 *
 * Only for evaluation: it refuses to run as soon as a user or a link exists. Click
 * distributions come from a seeded generator, so two runs look alike; dates are relative
 * to "now", so the charts are always filled up to today.
 */
final readonly class DemoSeeder
{
    public const string ADMIN_EMAIL = 'mara@nordlicht.example';

    /** @var list<string> */
    public const array DOMAINS = ['go.localhost', 'links.localhost', 'events.localhost'];

    private const array USERS = [
        'mara' => ['Mara Lindqvist', true],
        'jonas' => ['Jonas Weber', false],
        'lea' => ['Lea Okafor', false],
    ];

    private const array TAGS = ['Campaign', 'Social', 'Newsletter', 'Print', 'Events', 'Recruiting', 'App', 'Press'];

    private const string APP_STORE = 'https://apps.apple.com/app/nordlicht/id6449120001';
    private const string PLAY_STORE = 'https://play.google.com/store/apps/details?id=example.nordlicht';

    private const array COUNTRIES = ['DE' => 44, 'AT' => 10, 'CH' => 9, 'US' => 8, 'NL' => 5, 'FR' => 5, 'GB' => 5, 'SE' => 4, 'PL' => 3, 'DK' => 2, 'IT' => 2, 'ES' => 2, 'CA' => 1];
    private const array REFERRERS = ['' => 30, 'instagram.com' => 16, 'linkedin.com' => 14, 'google.com' => 10, 't.co' => 6, 'mail.google.com' => 6, 'outlook.live.com' => 4, 'facebook.com' => 5, 'nordlicht.example' => 6, 'news.ycombinator.com' => 2, 'reddit.com' => 1];
    private const array DEVICES = ['smartphone' => 54, 'desktop' => 38, 'tablet' => 8];
    private const array BROWSERS = [
        'smartphone' => ['Mobile Safari' => 45, 'Chrome Mobile' => 40, 'Samsung Browser' => 10, 'Firefox Mobile' => 5],
        'desktop' => ['Chrome' => 52, 'Safari' => 18, 'Microsoft Edge' => 16, 'Firefox' => 14],
        'tablet' => ['Mobile Safari' => 70, 'Chrome' => 30],
    ];
    private const array OS = ['Mobile Safari' => 'iOS', 'Chrome Mobile' => 'Android', 'Samsung Browser' => 'Android', 'Firefox Mobile' => 'Android', 'Safari' => 'Mac', 'Microsoft Edge' => 'Windows'];
    private const array LANGUAGES = ['de' => 62, 'en' => 28, 'fr' => 5, 'nl' => 3, 'sv' => 2];
    private const array HOURS = [7 => 3, 8 => 6, 9 => 9, 10 => 9, 11 => 8, 12 => 9, 13 => 8, 14 => 8, 15 => 7, 16 => 7, 17 => 6, 18 => 6, 19 => 6, 20 => 7, 21 => 6, 22 => 3, 23 => 1];

    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private LinkPassword $linkPasswords,
    ) {
    }

    /** True when there is nothing a demo could collide with: no user and no link yet. */
    public function isEmptyInstallation(): bool
    {
        return 0 === $this->em->getRepository(User::class)->count([])
            && 0 === $this->em->getRepository(Link::class)->count([]);
    }

    /**
     * @return array{users: list<string>, domains: list<string>, links: int, clicks: int}
     */
    public function seed(string $password, ?\DateTimeImmutable $now = null): array
    {
        if (!$this->isEmptyInstallation()) {
            throw new \LogicException('Demo data can only be added to an empty installation.');
        }
        $now ??= new \DateTimeImmutable();
        $random = new Randomizer(new Xoshiro256StarStar(20261108));

        $users = $this->createUsers($password);

        $domains = [];
        foreach (self::DOMAINS as $i => $hostname) {
            $domains[$hostname] = new Domain($hostname)->setIsDefault(0 === $i)
                ->setRootRedirectUrl(0 === $i ? 'https://nordlicht.example' : null);
            $this->em->persist($domains[$hostname]);
        }
        foreach ($users as $key => $user) {
            $user->addDomain($domains['go.localhost'])->addDomain($domains['links.localhost']);
            if ('jonas' !== $key) {
                $user->addDomain($domains['events.localhost']);
            }
        }

        $tags = [];
        foreach (self::TAGS as $name) {
            $tags[$name] = new Tag($users['mara'], $name);
            $this->em->persist($tags[$name]);
        }

        $links = [];
        foreach ($this->definitions($now) as $def) {
            $link = new Link($users[$def['owner']])
                ->setDomain($domains[$def['domain']])
                ->setCode($def['code'])
                ->setTitle($def['title'])
                ->setTargetUrl($def['target'])
                ->setStartsAt($def['starts'] ?? null)
                ->setExpiresAt($def['expires'] ?? null)
                ->setMaxClicks($def['max'] ?? null)
                ->setActive(!($def['inactive'] ?? false));
            foreach ($def['tags'] as $tag) {
                $link->addTag($tags[$tag]);
            }
            foreach ($def['languages'] ?? [] as $language => $url) {
                $link->addLocalisation(new LinkLocalisation()->setLanguage($language)->setTargetUrl($url));
            }
            foreach ($def['devices'] ?? [] as $device => $url) {
                $link->addDeviceTarget(new LinkDeviceTarget()->setDevice($device)->setTargetUrl($url));
            }
            if (isset($def['password'])) {
                $link->setPasswordHash($this->linkPasswords->hash($def['password']));
            }
            $this->em->persist($link);
            $links[] = [$link, $def];
        }
        $this->em->flush();

        $totalClicks = 0;
        foreach ($links as [$link, $def]) {
            [$count, $last] = $this->addClicks($link, $def, $now, $random);
            $totalClicks += $count;
            // Creation date and counters are not settable on the entity on purpose; the demo
            // backdates them in one statement instead of opening the model for it.
            $created = $now->modify(\sprintf('-%d days', $def['age']))->setTime(9, $random->getInt(0, 59));
            $this->em->createQuery('UPDATE '.Link::class.' l SET l.createdAt = :created, l.updatedAt = :created, l.clickCount = :count, l.lastClickAt = :last WHERE l.id = :id')
                ->execute(['created' => $created, 'count' => $count + ($def['earlier'] ?? 0), 'last' => $last, 'id' => $link->getId()]);
            $this->em->flush();
        }

        $this->addAuditTrail($users, $domains, $links, $now);

        return [
            'users' => array_map(static fn (User $u): string => $u->getEmail(), array_values($users)),
            'domains' => self::DOMAINS,
            'links' => \count($links),
            'clicks' => $totalClicks,
        ];
    }

    /** @return array<string, User> keyed by the local part of the e-mail address */
    private function createUsers(string $password): array
    {
        $users = [];
        foreach (self::USERS as $key => [$name, $admin]) {
            $user = new User()->setEmail($key.'@nordlicht.example')->setDisplayName($name)->setAdmin($admin);
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $this->em->persist($user);
            $users[$key] = $user;
        }

        return $users;
    }

    /**
     * @return list<array{code: string, domain: string, title: string, target: string, owner: string, age: int, weight: int, tags: list<string>, languages?: array<string, string>, devices?: array<string, string>, starts?: \DateTimeImmutable, expires?: \DateTimeImmutable, max?: int, earlier?: int, password?: string, inactive?: bool}>
     */
    private function definitions(\DateTimeImmutable $now): array
    {
        $at = static fn (string $modify): \DateTimeImmutable => $now->modify($modify)->setTime(23, 59);

        return [
            ['code' => 'herbst', 'domain' => 'go.localhost', 'title' => 'Autumn campaign', 'target' => 'https://nordlicht.example/kampagnen/herbst', 'owner' => 'mara', 'age' => 34, 'weight' => 38, 'tags' => ['Campaign', 'Social'],
                'languages' => ['en' => 'https://nordlicht.example/en/campaigns/autumn', 'fr' => 'https://nordlicht.example/fr/campagnes/automne'],
                'devices' => ['ios' => self::APP_STORE, 'android' => self::PLAY_STORE]],
            ['code' => 'app', 'domain' => 'go.localhost', 'title' => 'Nordlicht app download', 'target' => 'https://nordlicht.example/app', 'owner' => 'mara', 'age' => 58, 'weight' => 22, 'tags' => ['App'],
                'devices' => ['ios' => self::APP_STORE, 'android' => self::PLAY_STORE]],
            ['code' => 'jobs', 'domain' => 'go.localhost', 'title' => 'Careers: open positions', 'target' => 'https://jobs.nordlicht.example/de', 'owner' => 'lea', 'age' => 52, 'weight' => 14, 'tags' => ['Recruiting'],
                'languages' => ['en' => 'https://jobs.nordlicht.example/en']],
            ['code' => 'newsletter', 'domain' => 'links.localhost', 'title' => 'Newsletter: monthly issue', 'target' => 'https://nordlicht.example/newsletter?utm_source=newsletter', 'owner' => 'jonas', 'age' => 6, 'weight' => 12, 'tags' => ['Newsletter']],
            ['code' => 'summit', 'domain' => 'events.localhost', 'title' => 'Nordlicht Summit tickets', 'target' => 'https://tickets.nordlicht.example/summit', 'owner' => 'mara', 'age' => 27, 'weight' => 16, 'tags' => ['Events'],
                'expires' => $at('+27 days'), 'languages' => ['en' => 'https://tickets.nordlicht.example/en/summit']],
            ['code' => 'flyer', 'domain' => 'links.localhost', 'title' => 'Trade fair flyer (QR code)', 'target' => 'https://nordlicht.example/messe?src=flyer', 'owner' => 'jonas', 'age' => 21, 'weight' => 9, 'tags' => ['Print', 'Events']],
            ['code' => 'presse', 'domain' => 'go.localhost', 'title' => 'Press kit (password: nordlicht)', 'target' => 'https://nordlicht.example/presse/kit.zip', 'owner' => 'mara', 'age' => 44, 'weight' => 3, 'tags' => ['Press'], 'password' => 'nordlicht'],
            ['code' => 'linkedin', 'domain' => 'go.localhost', 'title' => 'LinkedIn company page', 'target' => 'https://www.linkedin.com/company/nordlicht-example', 'owner' => 'lea', 'age' => 60, 'weight' => 7, 'tags' => ['Social']],
            ['code' => 'beta', 'domain' => 'go.localhost', 'title' => 'Beta invite (limited)', 'target' => 'https://nordlicht.example/beta/invite', 'owner' => 'mara', 'age' => 16, 'weight' => 6, 'tags' => ['App'], 'max' => 300, 'earlier' => 88],
            ['code' => 'webinar', 'domain' => 'links.localhost', 'title' => 'Webinar recording: roadmap', 'target' => 'https://video.nordlicht.example/webinar', 'owner' => 'jonas', 'age' => 55, 'weight' => 4, 'tags' => [], 'expires' => $at('-8 days')],
            ['code' => 'black-week', 'domain' => 'go.localhost', 'title' => 'Black Week sale', 'target' => 'https://shop.nordlicht.example/black-week', 'owner' => 'mara', 'age' => 2, 'weight' => 0, 'tags' => ['Campaign'],
                'starts' => $now->modify('+16 days')->setTime(0, 0), 'expires' => $at('+23 days')],
            ['code' => 'docs', 'domain' => 'go.localhost', 'title' => 'Developer documentation', 'target' => 'https://docs.nordlicht.example', 'owner' => 'jonas', 'age' => 59, 'weight' => 5, 'tags' => []],
            ['code' => 'podcast', 'domain' => 'links.localhost', 'title' => 'Podcast: Building in public', 'target' => 'https://podcast.nordlicht.example/42', 'owner' => 'lea', 'age' => 11, 'weight' => 5, 'tags' => ['Social']],
            ['code' => 'feedback', 'domain' => 'go.localhost', 'title' => 'Customer feedback survey', 'target' => 'https://forms.nordlicht.example/feedback', 'owner' => 'lea', 'age' => 40, 'weight' => 2, 'tags' => [], 'inactive' => true],
        ];
    }

    /**
     * Daily clicks around the link's weight: quieter weekends, a launch peak in the first days
     * and a growing autumn campaign. Nothing after an expiry, nothing above a click limit.
     *
     * @param array{weight: int, age: int, code: string, expires?: \DateTimeImmutable, max?: int, earlier?: int} $def
     *
     * @return array{int, ?\DateTimeImmutable}
     */
    private function addClicks(Link $link, array $def, \DateTimeImmutable $now, Randomizer $random): array
    {
        $count = 0;
        $last = null;
        if (0 === $def['weight']) {
            return [0, null];
        }
        $budget = isset($def['max']) ? $def['max'] - ($def['earlier'] ?? 0) - 25 : \PHP_INT_MAX;
        for ($d = min($def['age'], 45); $d >= 0; --$d) {
            $day = $now->modify(\sprintf('-%d days', $d));
            if (isset($def['expires']) && $day > $def['expires']) {
                break;
            }
            $weekend = (int) $day->format('N') >= 6 ? 0.55 : 1.0;
            $sinceLaunch = $def['age'] - $d;
            $boost = $sinceLaunch < 4 ? 2.2 - 0.3 * $sinceLaunch : 1.0;
            if ('herbst' === $def['code']) {
                $boost *= 1 + max(0, 20 - $d) / 18;
            }
            $n = min((int) round($def['weight'] * $weekend * $boost * $random->getFloat(0.7, 1.3)), $budget - $count);
            // About a fifth of the clicks are repeat visits on the same day (same visitor hash).
            $visitors = [];
            for ($i = 0; $i < $n; ++$i) {
                $visitor = [] !== $visitors && $random->getInt(1, 100) <= 22
                    ? $visitors[$random->getInt(0, \count($visitors) - 1)]
                    : bin2hex($random->getBytes(32));
                $visitors[] = $visitor;
                $at = $day->setTime(self::pick(self::HOURS, $random), $random->getInt(0, 59), $random->getInt(0, 59));
                if ($at > $now) {
                    continue;
                }
                $this->em->persist($this->click($link, $at, $visitor, $random));
                ++$count;
                $last = null === $last || $at > $last ? $at : $last;
            }
        }
        $this->em->flush();
        $this->em->clear();

        return [$count, $last];
    }

    private function click(Link $link, \DateTimeImmutable $at, string $visitor, Randomizer $random): Click
    {
        $bot = $random->getInt(1, 100) <= 3;
        $device = 'bot';
        $browser = $os = null;
        if (!$bot) {
            $device = self::pick(self::DEVICES, $random);
            $browser = self::pick(self::BROWSERS[$device], $random);
            $os = self::OS[$browser] ?? ('tablet' === $device ? 'Android' : ($random->getInt(0, 1) ? 'Windows' : 'Mac'));
        }
        $referrer = self::pick(self::REFERRERS, $random);
        // The EntityManager is cleared between links; a reference avoids reloading the link.
        $reference = $this->em->getReference(Link::class, $link->getId());
        \assert($reference instanceof Link);

        return new Click($reference, $at, $visitor)
            ->setIpPrefix($random->getInt(0, 1) ? '203.0.113.0' : '198.51.100.0')
            ->setCountryCode(self::pick(self::COUNTRIES, $random))
            ->setReferrerHost('' === $referrer ? null : $referrer)
            ->setDeviceType($device)
            ->setBrowser($browser)
            ->setOs($os)
            ->setLanguage($bot ? null : self::pick(self::LANGUAGES, $random))
            ->setBot($bot);
    }

    /**
     * A short history so the audit log is not empty: sign-ins, the domains, a few link changes.
     *
     * @param array<string, User>                                     $users
     * @param array<string, Domain>                                   $domains
     * @param list<array{Link, array{age: int, target: string, ...}}> $links
     */
    private function addAuditTrail(array $users, array $domains, array $links, \DateTimeImmutable $now): void
    {
        $users = array_map(fn (User $u): User => $this->em->find(User::class, $u->getId()) ?? $u, $users);
        $entries = [];
        $add = function (string $action, User $actor, \DateTimeImmutable $at, array $context = [], ?string $type = null, ?int $id = null) use (&$entries): void {
            $entries[] = [new AuditEntry($action, $actor->getEmail(), $context, $actor->getId(), $type, $id, '192.0.2.'.(10 + (int) $actor->getId()), 'Mozilla/5.0 (demo)'), $at];
        };
        $mara = $users['mara'];
        foreach ($domains as $hostname => $domain) {
            $add(AuditLogger::DOMAIN_CREATED, $mara, $now->modify('-61 days')->setTime(8, 40), ['hostname' => $hostname], 'domain', $domain->getId());
        }
        foreach (['jonas', 'lea'] as $key) {
            $add(AuditLogger::USER_CREATED, $mara, $now->modify('-61 days')->setTime(8, 52), ['email' => $users[$key]->getEmail(), 'admin' => false], 'user', $users[$key]->getId());
        }
        foreach ($links as [$link, $def]) {
            $owner = $this->em->find(User::class, $link->getOwner()->getId()) ?? $mara;
            $add(AuditLogger::LINK_CREATED, $owner, $now->modify(\sprintf('-%d days', $def['age']))->setTime(9, 5), ['link' => $link->getShortUrl(), 'target' => $def['target']], 'link', $link->getId());
        }
        $add(AuditLogger::LINK_UPDATED, $mara, $now->modify('-3 days')->setTime(11, 12), ['link' => 'https://go.localhost/herbst', 'target' => 'https://nordlicht.example/kampagnen/herbst'], 'link', $links[0][0]->getId());
        foreach (['lea' => 2, 'jonas' => 1, 'mara' => 0] as $key => $daysAgo) {
            $add(AuditLogger::LOGIN, $users[$key], $now->modify(\sprintf('-%d days', $daysAgo))->setTime(8 + $daysAgo, 31), [], 'user', $users[$key]->getId());
        }

        foreach ($entries as [$entry]) {
            $this->em->persist($entry);
        }
        $this->em->flush();
        foreach ($entries as [$entry, $at]) {
            $this->em->createQuery('UPDATE '.AuditEntry::class.' a SET a.occurredAt = :at WHERE a.id = :id')
                ->execute(['at' => min($at, $now), 'id' => $entry->getId()]);
        }
    }

    /**
     * @template T of array-key
     *
     * @param array<T, int> $weights
     *
     * @return T
     */
    private static function pick(array $weights, Randomizer $random): int|string
    {
        $roll = $random->getInt(1, array_sum($weights));
        foreach ($weights as $value => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $value;
            }
        }

        throw new \LogicException('Weights must not be empty.');
    }
}
