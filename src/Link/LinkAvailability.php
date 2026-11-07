<?php

declare(strict_types=1);

namespace App\Link;

use App\Entity\Link;

/**
 * The single place that decides whether a link can redirect right now.
 *
 * The redirect controller, the list's status chip and filter and the API all read this one
 * decision instead of re-implementing the rules.
 *
 * Decision order (the first matching rule wins):
 *   1. not active            -> Inactive  (wins over every other reason)
 *   2. startsAt in the future -> Scheduled
 *   3. clickCount >= maxClicks -> Exhausted
 *   4. expiresAt in the past  -> Expired
 *   5. otherwise              -> null (available)
 *
 * Boundaries: startsAt exactly at "now" is NOT in the future, so the link is available;
 * a clickCount equal to maxClicks IS exhausted (>=). Comparisons use the same clock value
 * for a single call so the decision cannot flip while it runs.
 *
 * The click limit is deliberately approximate: clicks are counted in kernel.terminate, after
 * the response has been sent (no queue, no background worker), so a concurrent burst can let
 * a few extra clicks through before the counter catches up.
 */
final readonly class LinkAvailability
{
    /**
     * @param \DateTimeImmutable|null $now the reference time; injected in tests, defaults to the wall clock
     */
    public function reason(Link $link, ?\DateTimeImmutable $now = null): ?LinkUnavailability
    {
        $now ??= new \DateTimeImmutable();

        if (!$link->isActive()) {
            return LinkUnavailability::Inactive;
        }

        $startsAt = $link->getStartsAt();
        if (null !== $startsAt && $startsAt > $now) {
            return LinkUnavailability::Scheduled;
        }

        $maxClicks = $link->getMaxClicks();
        if (null !== $maxClicks && $link->getClickCount() >= $maxClicks) {
            return LinkUnavailability::Exhausted;
        }

        if ($link->isExpired($now)) {
            return LinkUnavailability::Expired;
        }

        return null;
    }

    public function isAvailable(Link $link, ?\DateTimeImmutable $now = null): bool
    {
        return null === $this->reason($link, $now);
    }

    /**
     * The status key shown in the UI: one of "live", "inactive", "scheduled", "exhausted"
     * or "expired". "live" means the link can redirect; the others are the enum's values.
     */
    public function status(Link $link, ?\DateTimeImmutable $now = null): string
    {
        $reason = $this->reason($link, $now);

        return null === $reason ? 'live' : $reason->value;
    }
}
