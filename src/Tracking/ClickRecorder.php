<?php

declare(strict_types=1);

namespace App\Tracking;

use App\Entity\Click;
use App\Entity\Link;
use App\Event\Tracking\ClickRecordedEvent;
use App\Event\Tracking\ClickRecordingEvent;
use App\Link\AcceptLanguageMatcher;
use App\Settings\Settings;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Records clicks after the redirect has been sent: kernel.terminate runs after
 * fastcgi_finish_request() under PHP-FPM, so tracking adds no latency and needs
 * no queue worker.
 *
 * Clicks are written through DBAL instead of the ORM: a click is an append-only row
 * and the counter update is a single UPDATE, so the identity map and unit of work
 * would only add overhead on the hot path.
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
final class ClickRecorder
{
    private const string ATTRIBUTE = '_emsl_click_link';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly VisitorHasher $visitorHasher,
        private readonly UserAgentInspector $userAgents,
        private readonly GeoIpLookup $geoIp,
        #[Autowire(service: 'limiter.redirect')]
        private readonly RateLimiterFactoryInterface $limiter,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function schedule(Request $request, Link $link): void
    {
        if (!$this->settings->getBool(Settings::TRACKING) || !$request->isMethod('GET') || self::isPrefetch($request)) {
            return;
        }

        $request->attributes->set(self::ATTRIBUTE, $link->getId());
    }

    /**
     * Schedules the click of a successful unlock. The unlock is a POST (the password form), and
     * that POST is the visitor's real arrival at the target, so it is counted even though the
     * regular schedule() only tracks GET requests. Bots and prefetches stay uncounted.
     */
    public function scheduleUnlock(Request $request, Link $link): void
    {
        if (!$this->settings->getBool(Settings::TRACKING) || self::isPrefetch($request)) {
            return;
        }

        $request->attributes->set(self::ATTRIBUTE, $link->getId());
    }

    public function __invoke(TerminateEvent $event): void
    {
        $linkId = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if (!\is_int($linkId)) {
            return;
        }

        try {
            $this->record($event->getRequest(), $linkId, new \DateTimeImmutable());
        } catch (\Throwable $e) {
            $this->logger->error('Click could not be recorded: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    public function record(Request $request, int $linkId, \DateTimeImmutable $at): ?Click
    {
        $link = $this->em->find(Link::class, $linkId);
        if (null === $link) {
            return null;
        }

        // Give plugins a veto before any write happens: a vetoed click is simply not
        // recorded (bots, internal traffic, a customer's test calls). This runs inside
        // kernel.terminate, after fastcgi_finish_request(), so listeners must be fast and
        // must not expect a response.
        $recording = new ClickRecordingEvent($request, $link);
        $this->dispatcher->dispatch($recording);
        if ($recording->isVetoed()) {
            $this->logger->info('Click recording vetoed by plugin: {reason}', [
                'reason' => $recording->getVetoReason(),
                'link' => $linkId,
            ]);

            return null;
        }

        // Rate-limit tracking per client so a script cannot grow the click table without
        // bound. This runs after the response has been sent (kernel.terminate), so the
        // visitor never waits for the limiter; a limiter failure fails open.
        try {
            $client = $request->getClientIp() ?? 'unknown';
            if (!$this->limiter->create($client)->consume()->isAccepted()) {
                $this->logger->debug('Click tracking rate limit exceeded for {client}.', ['client' => $client]);

                return null;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Click tracking rate limiter failed: {message}', ['message' => $e->getMessage()]);
        }

        $ip = $request->getClientIp();
        $userAgent = $request->headers->get('User-Agent');
        $agent = $this->userAgents->inspect($userAgent, array_map(
            static fn (array $values): string => (string) ($values[0] ?? ''),
            $request->headers->all(),
        ));

        if ($agent['bot']) {
            // Crawlers, link checkers and empty user agents are not visitors. Keeping them
            // out of the table keeps the statistics honest and the database small.
            return null;
        }

        $click = new Click($link, $at, $this->visitorHasher->hash($ip, $userAgent, $at))
            ->setIpPrefix(IpAnonymizer::anonymize($ip))
            ->setReferrerHost(self::referrerHost($request))
            ->setDeviceType($agent['device'])
            ->setBrowser($agent['browser'])
            ->setOs($agent['os'])
            ->setLanguage(AcceptLanguageMatcher::primaryLanguage($request->headers->get('Accept-Language')));

        if ($this->settings->getBool(Settings::TRACK_COUNTRY)) {
            $click->setCountryCode($this->geoIp->country($ip));
        }

        $uniqueVisitor = $this->insert($click, $linkId);

        // Still inside kernel.terminate: the row is committed, the counter is updated, and
        // the visitor already got their redirect - anything slow here costs nobody a
        // millisecond, but it can also never reach the response. A listener throwing must
        // not undo the click; the caller logs and moves on.
        $this->dispatcher->dispatch(new ClickRecordedEvent(
            $link,
            $at,
            $click->getCountryCode(),
            $click->getDeviceType(),
            $uniqueVisitor,
        ));

        return $click;
    }

    /** @return bool true when this visitor/day combination had not been seen before */
    private function insert(Click $click, int $linkId): bool
    {
        $connection = $this->em->getConnection();

        // The uniqueness probe: a count of zero means this visitor_hash had no click on this
        // link on this calendar day yet, so the row about to be written is the first one -
        // the same "visitor" the link statistics count with COUNT(DISTINCT visitor_hash).
        // It has to be its own statement instead of being folded into the counter UPDATE
        // with RETURNING: MySQL cannot use RETURNING with UPDATE at all, MariaDB only with
        // INSERT/DELETE. idx_click_day_visitor narrows it to this visitor's rows of the day.
        //
        // It deliberately runs BEFORE the transaction. On SQLite in WAL mode a deferred
        // transaction that reads first and writes second cannot be upgraded to a writer
        // once another connection committed in between: the INSERT then fails at once with
        // SQLITE_BUSY ("database is locked") instead of waiting for busy_timeout, and the
        // click is lost. Under parallel load that dropped ~8 % of the clicks. Starting the
        // transaction with the write lets busy_timeout queue the writers again. Two
        // simultaneous first clicks of one visitor may both be reported as unique - an
        // accepted imprecision of a best-effort hint that the statistics do not rely on.
        $seen = (int) $connection->executeQuery(
            'SELECT COUNT(*) FROM click WHERE clicked_day = ? AND visitor_hash = ? AND link_id = ?',
            [$click->getClickedDay()->format('Y-m-d'), $click->getVisitorHash(), $linkId],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
        )->fetchOne();

        $connection->beginTransaction();

        try {
            $connection->executeStatement(
                'INSERT INTO click (clicked_at, clicked_day, visitor_hash, ip_prefix, country_code, referrer_host, device_type, browser, os, language, bot, link_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $click->getClickedAt()->format('Y-m-d H:i:s'),
                    $click->getClickedDay()->format('Y-m-d'),
                    $click->getVisitorHash(),
                    $click->getIpPrefix(),
                    $click->getCountryCode(),
                    $click->getReferrerHost(),
                    $click->getDeviceType(),
                    $click->getBrowser(),
                    $click->getOs(),
                    $click->getLanguage(),
                    $click->isBot(),
                    $linkId,
                ],
                [
                    ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                    ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                    ParameterType::STRING, ParameterType::STRING, ParameterType::BOOLEAN, ParameterType::INTEGER,
                ],
            );

            // The counter update no longer probes anything: it only maintains the link's
            // click_count and last_click_at, exactly as before.
            $connection->executeStatement(
                'UPDATE link SET click_count = click_count + 1, last_click_at = ? WHERE id = ?',
                [$click->getClickedAt()->format('Y-m-d H:i:s'), $linkId],
                [ParameterType::STRING, ParameterType::INTEGER],
            );
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        return 0 === $seen;
    }

    private static function referrerHost(Request $request): ?string
    {
        $referrer = $request->headers->get('Referer');
        $host = null === $referrer ? null : parse_url($referrer, \PHP_URL_HOST);

        return \is_string($host) && '' !== $host ? strtolower($host) : null;
    }

    private static function isPrefetch(Request $request): bool
    {
        $purpose = strtolower((string) ($request->headers->get('Sec-Purpose') ?? $request->headers->get('Purpose')));

        return str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender');
    }
}
