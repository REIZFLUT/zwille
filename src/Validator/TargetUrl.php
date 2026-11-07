<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * An absolute http(s) URL that does not point back to one of our own
 * short-link or admin hosts (redirect loops). An agent token limited to certain target hosts
 * only accepts targets on those hosts.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class TargetUrl extends Constraint
{
    public string $invalidMessage = 'url.invalid';
    public string $loopMessage = 'url.loop';
    public string $notAllowedMessage = 'url.not_allowed_for_token';
}
