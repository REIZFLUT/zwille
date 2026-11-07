<?php

declare(strict_types=1);

namespace App\Link;

/**
 * Why a link cannot redirect right now.
 *
 * The case value is the status key used everywhere the state is shown (list chip, status
 * filter): "inactive", "scheduled", "exhausted", "expired". A link that can redirect has no
 * reason at all and is reported as "live" by LinkAvailability::status().
 */
enum LinkUnavailability: string
{
    /** Switched off by its owner; wins over every other reason. */
    case Inactive = 'inactive';

    /** startsAt is in the future: the campaign has not opened yet. */
    case Scheduled = 'scheduled';

    /** maxClicks has been reached (the counter is approximate, see LinkAvailability). */
    case Exhausted = 'exhausted';

    /** expiresAt is in the past. */
    case Expired = 'expired';
}
