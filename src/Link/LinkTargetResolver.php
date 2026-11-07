<?php

declare(strict_types=1);

namespace App\Link;

use App\Entity\Link;
use App\Tracking\UserAgentInspector;
use Symfony\Component\HttpFoundation\Request;

/**
 * The one place that decides which URL a visitor of a short link gets.
 *
 * Order of precedence:
 *   1. a device target (ios, android, desktop) for the visitor's operating system,
 *   2. a language localisation matching the Accept-Language header,
 *   3. the link's default target.
 *
 * Bots, empty user agents and anything the inspector does not recognise count as "desktop".
 * A link without device targets therefore resolves byte-identically to the previous
 * behaviour (language, then default).
 */
final readonly class LinkTargetResolver
{
    public function __construct(private UserAgentInspector $userAgents)
    {
    }

    public function resolve(Link $link, Request $request): string
    {
        $targets = $link->getDeviceTargetsMap();
        if ([] !== $targets) {
            $device = $this->device($request);
            if (isset($targets[$device])) {
                return $targets[$device];
            }
        }

        return $this->localised($link, $request);
    }

    /** The device key for a request: ios, android or desktop (the fallback for everything else). */
    public function device(Request $request): string
    {
        $headers = array_map(
            static fn (array $values): string => (string) ($values[0] ?? ''),
            $request->headers->all(),
        );
        $os = mb_strtolower((string) ($this->userAgents->inspect($request->headers->get('User-Agent'), $headers)['os'] ?? ''));

        return match ($os) {
            'ios', 'ipados' => 'ios',
            'android' => 'android',
            default => 'desktop',
        };
    }

    private function localised(Link $link, Request $request): string
    {
        $localised = $link->getLocalisedTargets();
        if ([] === $localised) {
            return $link->getTargetUrl();
        }
        $language = AcceptLanguageMatcher::bestMatch($request->headers->get('Accept-Language'), array_keys($localised));

        return null === $language ? $link->getTargetUrl() : $localised[$language];
    }
}
