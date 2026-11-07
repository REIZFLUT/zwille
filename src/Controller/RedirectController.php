<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\HostRegistry;
use App\Entity\Domain;
use App\Entity\Link;
use App\Event\Redirect\RedirectResolvingEvent;
use App\Event\Redirect\RedirectUnresolvedEvent;
use App\Link\LinkAvailability;
use App\Link\LinkPassword;
use App\Link\LinkTargetResolver;
use App\Link\LinkUnavailability;
use App\Link\LinkUnlockToken;
use App\Link\UnlockTokenStatus;
use App\Repository\LinkRepository;
use App\Tracking\ClickRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Serves every request on a short-link domain (see ShortLinkHostListener).
 */
final class RedirectController extends AbstractController
{
    public function __construct(
        private readonly HostRegistry $hosts,
        private readonly LinkRepository $links,
        private readonly ClickRecorder $clickRecorder,
        private readonly LinkAvailability $availability,
        private readonly LinkTargetResolver $resolver,
        private readonly LinkPassword $passwords,
        private readonly LinkUnlockToken $tokens,
        #[Autowire(service: 'limiter.link_gate')]
        private readonly RateLimiterFactoryInterface $gateLimiter,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $path = trim(rawurldecode($request->getPathInfo()), '/');
        if ('' === $path) {
            $domain = $this->activeDomain($request);
            if (null === $domain) {
                return $this->unknownDomain();
            }

            return null !== $domain->getRootRedirectUrl()
                ? $this->redirectTo($domain->getRootRedirectUrl(), Response::HTTP_FOUND)
                : $this->page('redirect/root.html.twig', Response::HTTP_OK, ['domain' => $domain]);
        }

        $link = str_contains($path, '/') ? null : $this->links->findForRedirectByHost($request->getHost(), $path);
        if (null !== $link) {
            $domain = $link->getDomain();
            // The link query intentionally does not filter on the domain flag: a link that sits
            // on a deactivated domain must look like an unknown host, not like a deleted link.
            if (null === $domain || !$domain->isActive()) {
                return $this->unknownDomain();
            }
            // Whether the link may redirect at all is decided in one place (LinkAvailability):
            // inactive, scheduled, exhausted or expired links leave here - before plugins see
            // the link and before any click is scheduled. A configured fallback target catches
            // every one of those reasons.
            $reason = $this->availability->reason($link);
            if (null !== $reason) {
                return $this->unavailable($reason, $link, $domain);
            }

            // Password gate, right after availability and before plugins or clicks: while the
            // visitor has not unlocked, no plugin sees the link and no click is scheduled, and
            // the target never reaches the response.
            if ($link->hasPassword()) {
                $gate = $this->unlock($request, $link, $domain);
                if (null !== $gate) {
                    return $gate;
                }
            }

            $resolving = new RedirectResolvingEvent($request, $link, $this->resolver->resolve($link, $request), $link->getRedirectType());
            $this->dispatcher->dispatch($resolving);
            if ($resolving->isVetoed()) {
                // A veto must be indistinguishable from an unknown link: the visitor gets the
                // 404 page and no click is recorded. Only the log knows a plugin intervened.
                $this->logger->info('Redirect vetoed by plugin: {reason}', [
                    'reason' => $resolving->getVetoReason(),
                    'link' => $link->getId(),
                ]);

                return $this->page('redirect/not_found.html.twig', Response::HTTP_NOT_FOUND, ['domain' => $domain]);
            }

            $this->clickRecorder->schedule($request, $link);

            return $this->redirectTo($resolving->getTarget(), $resolving->getStatusCode());
        }

        $domain = $this->activeDomain($request);
        // Unknown path: give plugins a chance to turn it into a redirect of their own before
        // the configured fallback runs. The domain is passed even when it is inactive; it is
        // only null for a host that is no short domain at all. resolveDomain() is memoised by
        // the registry, so this adds no query.
        $unresolved = new RedirectUnresolvedEvent($request, $this->hosts->resolveDomain($request->getHost()));
        $this->dispatcher->dispatch($unresolved);
        if ($unresolved->hasTarget()) {
            return $this->redirectTo((string) $unresolved->getTarget(), $unresolved->getStatusCode());
        }
        if (null === $domain) {
            return $this->unknownDomain();
        }

        $fallback = $domain->getNotFoundUrl();
        if (null === $fallback) {
            return $this->page('redirect/not_found.html.twig', Response::HTTP_NOT_FOUND, ['domain' => $domain]);
        }
        if ($domain->isForwardUnknownPaths()) {
            $fallback = rtrim($fallback, '/').'/'.ltrim($request->getPathInfo(), '/');
        }

        return $this->redirectTo($fallback, Response::HTTP_FOUND);
    }

    /** Resolved short-link domain, but only when it is active. */
    private function activeDomain(Request $request): ?Domain
    {
        $domain = $this->hosts->resolveDomain($request->getHost());

        return null !== $domain && $domain->isActive() ? $domain : null;
    }

    private function unknownDomain(): Response
    {
        return $this->page('redirect/unknown_domain.html.twig', Response::HTTP_NOT_FOUND);
    }

    /**
     * A link that cannot redirect right now. A configured fallback wins over the notice pages;
     * a scheduled link without one must be indistinguishable from an unknown code (404, not 410),
     * while disabled, exhausted and expired links keep the gone page.
     */
    private function unavailable(LinkUnavailability $reason, Link $link, Domain $domain): Response
    {
        $fallback = $link->getFallbackUrl();
        if (null !== $fallback) {
            return $this->redirectTo($fallback, Response::HTTP_FOUND);
        }

        if (LinkUnavailability::Scheduled === $reason) {
            return $this->page('redirect/not_found.html.twig', Response::HTTP_NOT_FOUND, ['domain' => $domain]);
        }
        if (LinkUnavailability::Exhausted === $reason) {
            return $this->page('redirect/exhausted.html.twig', Response::HTTP_GONE, ['domain' => $domain]);
        }

        return $this->page('redirect/gone.html.twig', Response::HTTP_GONE, ['domain' => $domain]);
    }

    /**
     * Password gate of a protected link.
     *
     * Returns null once the visitor unlocked (the normal redirect flow continues); otherwise the
     * response to send: the form, the throttled page or - for a foreign or forged token - the
     * unknown-link page.
     */
    private function unlock(Request $request, Link $link, Domain $domain): ?Response
    {
        if (!$request->isMethod('POST')) {
            return $this->unlockFormOrThrottle($request, $link, $domain);
        }

        $code = (string) $request->request->get('code', '');
        $expiry = (int) $request->request->get('expiry', 0);
        $mac = (string) $request->request->get('mac', '');

        // A token bound to another link, or a rewritten mac, is indistinguishable from an unknown
        // code: the visitor gets the 404 page, never a form.
        if ($code !== $link->getCode()) {
            return $this->page('redirect/not_found.html.twig', Response::HTTP_NOT_FOUND, ['domain' => $domain]);
        }
        $status = $this->tokens->verify($link->getCode(), $expiry, $mac);
        if (UnlockTokenStatus::Invalid === $status) {
            return $this->page('redirect/not_found.html.twig', Response::HTTP_NOT_FOUND, ['domain' => $domain]);
        }

        $password = $request->request->get('password');
        if (UnlockTokenStatus::Expired === $status || !\is_string($password) || '' === $password) {
            // An overdue token or a missing password field simply gets a fresh form - no hint
            // about closeness, no attempt counted.
            return $this->unlockFormOrThrottle($request, $link, $domain);
        }

        $limiter = $this->gateLimiter->create($this->gateKey($request, $link));
        if (!$limiter->consume()->isAccepted()) {
            return $this->unlockThrottled($domain);
        }
        if (!$this->passwords->verify($password, $link->getPasswordHash())) {
            return $this->unlockForm($request, $link, $domain, error: true);
        }

        // Correct password: release the attempts and count this arrival as the click, then let
        // the normal path (plugin event, redirect) run.
        $limiter->reset();
        $this->clickRecorder->scheduleUnlock($request, $link);

        return null;
    }

    /** Serves the form, unless the attempts are used up - then the throttled page, never the form. */
    private function unlockFormOrThrottle(Request $request, Link $link, Domain $domain): Response
    {
        // consume(0) only reads the remaining tokens (it writes nothing back).
        if ($this->gateLimiter->create($this->gateKey($request, $link))->consume(0)->getRemainingTokens() < 1) {
            return $this->unlockThrottled($domain);
        }

        return $this->unlockForm($request, $link, $domain);
    }

    private function unlockForm(Request $request, Link $link, Domain $domain, bool $error = false): Response
    {
        $token = $this->tokens->issue($link->getCode());

        return $this->page('redirect/password.html.twig', Response::HTTP_OK, [
            'domain' => $domain,
            'code' => $link->getCode(),
            'expiry' => $token['expiry'],
            'mac' => $token['mac'],
            'action' => $request->getPathInfo(),
            'error' => $error,
        ]);
    }

    private function unlockThrottled(Domain $domain): Response
    {
        return $this->page('redirect/throttled.html.twig', Response::HTTP_TOO_MANY_REQUESTS, ['domain' => $domain]);
    }

    /** Rate-limit key: client address plus short code, so one IP cannot hammer one link. */
    private function gateKey(Request $request, Link $link): string
    {
        return ($request->getClientIp() ?? 'unknown').'|'.$link->getCode();
    }

    private function redirectTo(string $url, int $status): RedirectResponse
    {
        $response = new RedirectResponse($url, $status);
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        if (\in_array($status, [Response::HTTP_MOVED_PERMANENTLY, Response::HTTP_PERMANENTLY_REDIRECT], true)) {
            // Browsers cache permanent redirects anyway; keep it bounded so changes still arrive.
            $response->setPublic();
            $response->setMaxAge(3600);
            // The target depends on Accept-Language; without this a proxy/CDN would serve
            // every visitor the language of whichever request populated the cache first.
            $response->setVary('Accept-Language');
        } else {
            // Temporary redirects must reach us every time, or clicks go uncounted.
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    /** @param array<string, mixed> $context */
    private function page(string $template, int $status, array $context = []): Response
    {
        $response = $this->render($template, $context, new Response(status: $status));
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
