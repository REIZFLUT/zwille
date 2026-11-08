<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Strict CSP (scripts only from 'self' plus a per-request nonce for the importmap),
 * no framing, no MIME sniffing.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
final class SecurityHeadersListener
{
    public const string NONCE_ATTRIBUTE = '_csp_nonce';

    /**
     * Extra form-action sources for this response (list of strings). Browsers apply form-action
     * to the redirects that follow a form submission too, so a form that ends in a redirect to
     * an external site (the password gate of a short link) needs that site allowed here.
     */
    public const string FORM_ACTION_ATTRIBUTE = '_csp_form_action';

    public static function nonce(Request $request): string
    {
        $nonce = $request->attributes->get(self::NONCE_ATTRIBUTE);
        if (!\is_string($nonce)) {
            $nonce = base64_encode(random_bytes(18));
            $request->attributes->set(self::NONCE_ATTRIBUTE, $nonce);
        }

        return $nonce;
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $headers = $event->getResponse()->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        if (!$headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'same-origin');
        }
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        $contentType = (string) $headers->get('Content-Type', 'text/html');
        if (!str_contains($contentType, 'html') || $headers->has('Content-Security-Policy')) {
            return;
        }
        $headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-".self::nonce($request)."'",
            "style-src 'self'",
            "img-src 'self' data:",
            "connect-src 'self'",
            "font-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            implode(' ', ["form-action 'self'", ...self::extraFormActions($request)]),
            "frame-ancestors 'none'",
        ]));
    }

    /** @return list<string> */
    private static function extraFormActions(Request $request): array
    {
        $sources = $request->attributes->get(self::FORM_ACTION_ATTRIBUTE, []);

        return \is_array($sources) ? array_values(array_filter($sources, 'is_string')) : [];
    }
}
