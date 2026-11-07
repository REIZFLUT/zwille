<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Link;
use App\Entity\User;
use App\Link\LinkAvailability;
use App\Link\LinkUnavailability;
use PHPUnit\Framework\TestCase;

final class LinkAvailabilityTest extends TestCase
{
    private const string NOW = '2026-11-07 12:00:00';

    private LinkAvailability $availability;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->availability = new LinkAvailability();
        $this->now = new \DateTimeImmutable(self::NOW);
    }

    public function testPlainLinkIsAvailable(): void
    {
        $link = $this->link();

        self::assertNull($this->availability->reason($link, $this->now));
        self::assertTrue($this->availability->isAvailable($link, $this->now));
        self::assertSame('live', $this->availability->status($link, $this->now));
    }

    public function testInactiveLinkWinsOverEverythingElse(): void
    {
        // Inactive, scheduled in the future, limit reached and expired at once: still Inactive.
        $link = $this->link(startsAt: '+1 day', expiresAt: '-1 day', maxClicks: 1, clicks: 5)
            ->setActive(false);

        self::assertSame(LinkUnavailability::Inactive, $this->availability->reason($link, $this->now));
        self::assertFalse($this->availability->isAvailable($link, $this->now));
        self::assertSame('inactive', $this->availability->status($link, $this->now));
    }

    public function testFutureStartsAtIsScheduled(): void
    {
        $link = $this->link(startsAt: '+1 second');

        self::assertSame(LinkUnavailability::Scheduled, $this->availability->reason($link, $this->now));
        self::assertSame('scheduled', $this->availability->status($link, $this->now));
    }

    public function testStartsAtExactlyNowIsAvailable(): void
    {
        // Boundary: "startsAt exactly now" is not in the future, so the link is live.
        $link = $this->link(startsAt: 'now');

        self::assertNull($this->availability->reason($link, $this->now));
        self::assertSame('live', $this->availability->status($link, $this->now));
    }

    public function testPastStartsAtIsAvailable(): void
    {
        self::assertNull($this->availability->reason($this->link(startsAt: '-1 second'), $this->now));
    }

    public function testReachedClickLimitIsExhausted(): void
    {
        // Boundary: clickCount equal to maxClicks is exhausted (>=).
        $link = $this->link(maxClicks: 3, clicks: 3);

        self::assertSame(LinkUnavailability::Exhausted, $this->availability->reason($link, $this->now));
        self::assertSame('exhausted', $this->availability->status($link, $this->now));
    }

    public function testClickLimitNotYetReachedIsAvailable(): void
    {
        self::assertNull($this->availability->reason($this->link(maxClicks: 3, clicks: 2), $this->now));
    }

    public function testScheduledWinsOverReachedClickLimit(): void
    {
        $link = $this->link(startsAt: '+1 day', maxClicks: 1, clicks: 1);

        self::assertSame(LinkUnavailability::Scheduled, $this->availability->reason($link, $this->now));
    }

    public function testReachedClickLimitWinsOverExpiry(): void
    {
        $link = $this->link(expiresAt: '-1 day', maxClicks: 1, clicks: 1);

        self::assertSame(LinkUnavailability::Exhausted, $this->availability->reason($link, $this->now));
    }

    public function testPastExpiryIsExpired(): void
    {
        $link = $this->link(expiresAt: '-1 second');

        self::assertSame(LinkUnavailability::Expired, $this->availability->reason($link, $this->now));
        self::assertSame('expired', $this->availability->status($link, $this->now));
    }

    public function testExpiryExactlyNowIsExpired(): void
    {
        // Boundary: isExpired() uses "<= now".
        self::assertSame(LinkUnavailability::Expired, $this->availability->reason($this->link(expiresAt: 'now'), $this->now));
    }

    public function testFutureExpiryIsAvailable(): void
    {
        self::assertNull($this->availability->reason($this->link(expiresAt: '+1 second'), $this->now));
    }

    private function link(?string $startsAt = null, ?string $expiresAt = null, ?int $maxClicks = null, int $clicks = 0): Link
    {
        $link = new Link(new User());
        if (null !== $startsAt) {
            $link->setStartsAt($this->now->modify($startsAt));
        }
        if (null !== $expiresAt) {
            $link->setExpiresAt($this->now->modify($expiresAt));
        }
        $link->setMaxClicks($maxClicks);

        // clickCount has no setter (it is maintained by the ClickRecorder); set it for the test.
        new \ReflectionProperty(Link::class, 'clickCount')->setValue($link, $clicks);

        return $link;
    }
}
