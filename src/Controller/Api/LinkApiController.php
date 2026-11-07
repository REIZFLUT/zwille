<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Domain;
use App\Entity\Link;
use App\Entity\LinkDeviceTarget;
use App\Entity\LinkLocalisation;
use App\Entity\Tag;
use App\Entity\User;
use App\Event\Api\ApiResponseEvent;
use App\Link\LinkManager;
use App\Link\LinkPassword;
use App\Link\LinkRejectedException;
use App\Repository\ClickRepository;
use App\Repository\DomainRepository;
use App\Repository\LinkRepository;
use App\Security\AdminAccess;
use App\Security\ApiScope;
use App\Security\Attribute\RequiresScope;
use App\Security\CurrentApiToken;
use App\Security\Voter\DomainVoter;
use App\Security\Voter\LinkVoter;
use App\Stats\Period;
use App\Stats\StatsBuilder;
use App\Tag\TagManager;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * REST API v1, authenticated with personal access tokens ("Authorization: Bearer emsl_…").
 * See docs/API.md.
 */
#[Route('/api/v1', format: 'json')]
final class LinkApiController extends AbstractController
{
    private const int PER_PAGE = 50;

    public function __construct(
        private readonly LinkManager $manager,
        private readonly DomainRepository $domains,
        #[Autowire(service: 'limiter.api')]
        private readonly RateLimiterFactoryInterface $apiLimiter,
        private readonly AdminAccess $adminAccess,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly TagManager $tagManager,
        private readonly LinkPassword $passwords,
        private readonly CurrentApiToken $apiToken,
    ) {
    }

    #[Route('/domains', name: 'api_domains', methods: ['GET'])]
    #[RequiresScope(ApiScope::DOMAINS_READ)]
    public function domains(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->throttle($user);

        return $this->ok('domains.list', $request, ['data' => array_map(static fn (Domain $d): array => [
            'id' => $d->getId(),
            'hostname' => $d->getHostname(),
            'default' => $d->isDefault(),
        ], $this->usableDomains($user))]);
    }

    #[Route('/links', name: 'api_links', methods: ['GET'])]
    #[RequiresScope(ApiScope::LINKS_READ)]
    public function list(Request $request, #[CurrentUser] User $user, LinkRepository $links): JsonResponse
    {
        $this->throttle($user);
        $page = max(1, $request->query->getInt('page', 1));
        $domainId = $request->query->getInt('domain');
        $query = $links->searchQuery($user, $request->query->getString('q'), $domainId > 0 ? $this->domains->find($domainId) : null, !$this->adminAccess->isAdmin($user));
        $token = $this->apiToken->get();
        if (null !== $token && $token->restrictsDomains()) {
            $query->andWhere('l.domain IN (:tokenDomains)')->setParameter('tokenDomains', $token->getDomains()->toArray());
        }
        $query->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE);
        $paginator = new Paginator($query, fetchJoinCollection: false);

        return $this->ok('links.list', $request, [
            'data' => array_map(self::normalize(...), iterator_to_array($paginator, false)),
            'meta' => ['page' => $page, 'per_page' => self::PER_PAGE, 'total' => \count($paginator)],
        ]);
    }

    #[Route('/links', name: 'api_link_create', methods: ['POST'])]
    #[RequiresScope(ApiScope::LINKS_WRITE)]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->throttle($user);
        $data = $this->payload($request);
        $link = new Link($user);
        $this->manager->applyDefaults($link);

        $link->setDomain($this->usableDomains($user)[0] ?? null);
        $error = $this->apply($link, $data);
        if (null !== $error) {
            return $error;
        }

        $violations = $this->manager->validate($link);
        if (\count($violations) > 0) {
            return $this->violations($violations);
        }
        try {
            $this->manager->save($link);
        } catch (LinkRejectedException $exception) {
            return $this->rejected($exception);
        }

        return $this->ok('links.create', $request, ['data' => self::normalize($link)], Response::HTTP_CREATED);
    }

    #[Route('/links/{id}', name: 'api_link_show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[RequiresScope(ApiScope::LINKS_READ)]
    #[IsGranted(LinkVoter::MANAGE, 'link')]
    public function show(Request $request, Link $link, #[CurrentUser] User $user): JsonResponse
    {
        $this->throttle($user);

        return $this->ok('links.show', $request, ['data' => self::normalize($link)]);
    }

    #[Route('/links/{id}', name: 'api_link_update', requirements: ['id' => Requirement::DIGITS], methods: ['PATCH'])]
    #[RequiresScope(ApiScope::LINKS_WRITE)]
    #[IsGranted(LinkVoter::MANAGE, 'link')]
    public function update(Request $request, Link $link, #[CurrentUser] User $user): JsonResponse
    {
        $this->throttle($user);
        $error = $this->apply($link, $this->payload($request));
        if (null !== $error) {
            return $error;
        }
        $violations = $this->manager->validate($link);
        if (\count($violations) > 0) {
            return $this->violations($violations);
        }
        try {
            $this->manager->save($link);
        } catch (LinkRejectedException $exception) {
            return $this->rejected($exception);
        }

        return $this->ok('links.update', $request, ['data' => self::normalize($link)]);
    }

    #[Route('/links/{id}', name: 'api_link_delete', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    #[RequiresScope(ApiScope::LINKS_DELETE)]
    #[IsGranted(LinkVoter::MANAGE, 'link')]
    public function delete(Link $link, #[CurrentUser] User $user): Response
    {
        $this->throttle($user);
        $this->manager->delete($link);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/links/{id}/stats', name: 'api_link_stats', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[RequiresScope(ApiScope::STATS_READ)]
    #[IsGranted(LinkVoter::MANAGE, 'link')]
    public function stats(Request $request, Link $link, #[CurrentUser] User $user, StatsBuilder $stats): JsonResponse
    {
        $this->throttle($user);
        $result = $stats->build(Period::lastDays($request->query->getInt('days', 30)), link: $link);

        return $this->ok('links.stats', $request, [
            'data' => [
                'from' => $result['period']->from->format('Y-m-d'),
                'to' => $result['period']->to->format('Y-m-d'),
                'totals' => $result['totals'],
                'per_day' => array_map(static fn (array $p): array => [
                    'day' => $p['day']->format('Y-m-d'),
                    'clicks' => $p['clicks'],
                    'visitors' => $p['visitors'],
                ], $result['series']),
                'breakdowns' => $result['breakdowns'],
            ],
        ]);
    }

    /**
     * YOURLS-compatible resolution of a short URL or a bare code to its target. Counts no
     * click and never redirects; permissions match reading a link (owner or admin).
     */
    #[Route('/expand', name: 'api_expand', methods: ['GET'])]
    #[RequiresScope(ApiScope::LINKS_READ)]
    public function expand(Request $request, #[CurrentUser] User $user, LinkRepository $links): JsonResponse
    {
        $this->throttle($user);

        $url = trim($request->query->getString('url'));
        if ('' === $url) {
            throw new BadRequestHttpException('Parameter "url" is required.');
        }

        $parts = parse_url($url);
        $scheme = \is_array($parts) ? mb_strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = \is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $isFullUrl = \in_array($scheme, ['http', 'https'], true) && '' !== $host;

        if ($isFullUrl) {
            $domain = $this->domains->findOneByHostname($host);
            $code = trim((string) parse_url($url, \PHP_URL_PATH), '/');
        } else {
            $code = $url;
            $domainHost = trim($request->query->getString('domain'));
            $domain = '' !== $domainHost ? $this->domains->findOneByHostname($domainHost) : null;
            if (null === $domain && '' === $domainHost) {
                // No domain given: the user's default domain (first of the usable ones).
                $domain = $this->usableDomains($user)[0] ?? null;
            }
        }

        // An unknown or inactive domain is reported like an unknown link (404) below.
        if (null === $domain || !$domain->isActive() || '' === $code) {
            throw new NotFoundHttpException('Unknown short link.');
        }

        $link = $links->findForRedirectByHost($domain->getHostname(), mb_strtolower($code));
        if (null === $link) {
            throw new NotFoundHttpException('Unknown short link.');
        }
        $this->denyAccessUnlessGranted(LinkVoter::MANAGE, $link);

        return $this->ok('links.expand', $request, ['data' => [
            'id' => $link->getId(),
            'code' => $link->getCode(),
            'short_url' => $link->getShortUrl(),
            'target_url' => $link->getTargetUrl(),
            'active' => $link->isActive(),
            'expires_at' => $link->getExpiresAt()?->format(\DATE_ATOM),
        ]]);
    }

    /**
     * Account-wide statistics (all links for admins from allowed addresses) for the last
     * N days, plus the most clicked links of the same period and scope.
     */
    #[Route('/stats', name: 'api_stats', methods: ['GET'])]
    #[RequiresScope(ApiScope::STATS_READ)]
    public function globalStats(Request $request, #[CurrentUser] User $user, StatsBuilder $stats, ClickRepository $clicks): JsonResponse
    {
        $this->throttle($user);
        if (true === $this->apiToken->get()?->restrictsDomains()) {
            // Account-wide figures would include links on domains the token must not see.
            return $this->json([
                'error' => 'scope_restricted',
                'message' => 'Account-wide statistics are not available to a token limited to certain domains; use /links/{id}/stats.',
            ], Response::HTTP_FORBIDDEN);
        }

        $days = $request->query->getInt('days', 30);
        if (!\in_array($days, Period::CHOICES, true)) {
            throw new BadRequestHttpException(\sprintf('Parameter "days" must be one of: %s.', implode(', ', Period::CHOICES)));
        }
        $period = Period::lastDays($days);
        $owner = $this->adminAccess->isAdmin($user) ? null : $user;
        $result = $stats->build($period, owner: $owner);

        $topLinks = array_map(static fn (array $row): array => [
            'id' => $row['link']->getId(),
            'code' => $row['link']->getCode(),
            'short_url' => $row['link']->getShortUrl(),
            'target_url' => $row['link']->getTargetUrl(),
            'clicks' => $row['clicks'],
        ], $clicks->topLinks($period->from, $period->to, $owner, 10));

        return $this->ok('stats.show', $request, [
            'data' => [
                'from' => $period->from->format('Y-m-d'),
                'to' => $period->to->format('Y-m-d'),
                'totals' => $result['totals'],
                'per_day' => array_map(static fn (array $day): array => [
                    'day' => $day['day']->format('Y-m-d'),
                    'clicks' => $day['clicks'],
                    'visitors' => $day['visitors'],
                ], $result['series']),
                'breakdowns' => $result['breakdowns'],
                'top_links' => $topLinks,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public static function normalize(Link $link): array
    {
        return [
            'id' => $link->getId(),
            'short_url' => $link->getShortUrl(),
            'domain' => $link->getDomain()?->getHostname(),
            'code' => $link->getCode(),
            'target_url' => $link->getTargetUrl(),
            'title' => $link->getTitle(),
            'redirect_type' => $link->getRedirectType(),
            'active' => $link->isActive(),
            'starts_at' => $link->getStartsAt()?->format(\DATE_ATOM),
            'expires_at' => $link->getExpiresAt()?->format(\DATE_ATOM),
            'max_clicks' => $link->getMaxClicks(),
            'fallback_url' => $link->getFallbackUrl(),
            'localisations' => (object) $link->getLocalisedTargets(),
            'password_protected' => $link->hasPassword(),
            'device_targets' => (object) $link->getDeviceTargetsMap(),
            'tags' => array_values(array_map(static fn (Tag $tag): string => $tag->getName(), $link->getTags()->toArray())),
            'clicks' => $link->getClickCount(),
            'last_click_at' => $link->getLastClickAt()?->format(\DATE_ATOM),
            'owner' => $link->getOwner()->getEmail(),
            'created_at' => $link->getCreatedAt()->format(\DATE_ATOM),
            'updated_at' => $link->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @param array<string, mixed> $data */
    private function apply(Link $link, array $data): ?JsonResponse
    {
        // The internal note is never accepted through the API (and never returned).
        if (\array_key_exists('notes', $data)) {
            return $this->error('notes', 'Notes are internal and cannot be set through the API.');
        }

        if (\array_key_exists('domain', $data)) {
            $domain = \is_int($data['domain']) ? $this->domains->find($data['domain']) : $this->domains->findOneByHostname((string) $data['domain']);
            if (null === $domain || !$this->isGranted(DomainVoter::USE, $domain)) {
                return $this->error('domain', 'Unknown domain or not allowed for this user.');
            }
            $link->setDomain($domain);
        } elseif (null === $link->getDomain()) {
            return $this->error('domain', 'No domain available for this user.');
        }

        if (\array_key_exists('code', $data)) {
            $link->setCode(\is_string($data['code']) ? $data['code'] : '');
        }
        if (\array_key_exists('target_url', $data)) {
            $link->setTargetUrl(\is_string($data['target_url']) ? $data['target_url'] : '');
        }
        if (\array_key_exists('title', $data)) {
            $link->setTitle(\is_string($data['title']) ? $data['title'] : null);
        }
        if (\array_key_exists('redirect_type', $data)) {
            $link->setRedirectType(\is_int($data['redirect_type']) ? $data['redirect_type'] : 0);
        }
        if (\array_key_exists('active', $data)) {
            $link->setActive(true === $data['active']);
        }
        if (\array_key_exists('expires_at', $data)) {
            try {
                $link->setExpiresAt(null === $data['expires_at'] ? null : new \DateTimeImmutable((string) $data['expires_at']));
            } catch (\Exception) {
                return $this->error('expires_at', 'Invalid date, use ISO 8601.');
            }
        }
        if (\array_key_exists('starts_at', $data)) {
            try {
                $link->setStartsAt(null === $data['starts_at'] ? null : new \DateTimeImmutable((string) $data['starts_at']));
            } catch (\Exception) {
                return $this->error('starts_at', 'Invalid date, use ISO 8601.');
            }
        }
        if (\array_key_exists('max_clicks', $data)) {
            $maxClicks = $data['max_clicks'];
            if (null === $maxClicks) {
                $link->setMaxClicks(null);
            } elseif (\is_int($maxClicks)) {
                $link->setMaxClicks($maxClicks);
            } elseif (\is_string($maxClicks) && ctype_digit($maxClicks)) {
                $link->setMaxClicks((int) $maxClicks);
            } else {
                // Anything else (0, negative, bool, float, junk) fails the Positive constraint -> 422.
                $link->setMaxClicks(0);
            }
        }
        if (\array_key_exists('fallback_url', $data)) {
            $fallbackUrl = $data['fallback_url'];
            if (null === $fallbackUrl) {
                $link->setFallbackUrl(null);
            } elseif (\is_scalar($fallbackUrl)) {
                // TargetUrl on the entity rejects anything that is not http(s).
                $link->setFallbackUrl((string) $fallbackUrl);
            } else {
                return $this->error('fallback_url', 'Expected an http(s) URL.');
            }
        }
        if (\array_key_exists('localisations', $data)) {
            if (!\is_array($data['localisations'])) {
                return $this->error('localisations', 'Expected an object like {"en": "https://…"}.');
            }
            foreach ($link->getLocalisations()->toArray() as $existing) {
                $link->removeLocalisation($existing);
            }
            foreach ($data['localisations'] as $language => $url) {
                $link->addLocalisation(new LinkLocalisation()->setLanguage((string) $language)->setTargetUrl(\is_string($url) ? $url : ''));
            }
        }
        if (\array_key_exists('tags', $data)) {
            if (!\is_array($data['tags'])) {
                return $this->error('tags', 'Expected an array of tag names.');
            }
            $names = [];
            foreach ($data['tags'] as $name) {
                if (!\is_string($name)) {
                    return $this->error('tags', 'Tag names must be strings.');
                }
                $names[] = $name;
            }
            // Unknown names are created for the link's owner; the whole set replaces the current one.
            $link->setTags($this->tagManager->resolve($link->getOwner(), $names));
        }
        if (\array_key_exists('password', $data)) {
            $password = $data['password'];
            if (null === $password) {
                // null (or absent): leave the current protection unchanged - mirrors the web form's
                // empty field. An empty string is the explicit "remove the password".
            } elseif (!\is_string($password)) {
                return $this->error('password', 'Expected a string or null.');
            } elseif ('' === $password) {
                $link->clearPassword();
            } else {
                $passwordError = $this->manager->passwordError($password);
                if (null !== $passwordError) {
                    return $this->error('password', $passwordError);
                }
                $link->setPasswordHash($this->passwords->hash($password));
            }
        }
        if (\array_key_exists('device_targets', $data)) {
            if (!\is_array($data['device_targets'])) {
                return $this->error('device_targets', 'Expected an array like [{"device": "ios", "url": "https://…"}].');
            }
            /** @var array<string, string> $targets */
            $targets = [];
            foreach ($data['device_targets'] as $entry) {
                if (!\is_array($entry) || !isset($entry['device'], $entry['url'])) {
                    return $this->error('device_targets', 'Each entry needs "device" and "url".');
                }
                $device = $entry['device'];
                if (!\is_string($device) || !\in_array($device, LinkDeviceTarget::DEVICES, true)) {
                    return $this->error('device_targets', 'Device must be one of: ios, android, desktop.');
                }
                if (!\is_string($entry['url'])) {
                    return $this->error('device_targets', 'The url must be a string.');
                }
                if (isset($targets[$device])) {
                    return $this->error('device_targets', 'Each device may only be given once.');
                }
                $targets[$device] = $entry['url'];
            }
            // The submitted set replaces the stored one; a non-http url is caught by the entity's
            // TargetUrl constraint below and reported as 422.
            foreach ($link->getDeviceTargets()->toArray() as $existing) {
                $link->removeDeviceTarget($existing);
            }
            foreach ($targets as $device => $url) {
                $link->addDeviceTarget(new LinkDeviceTarget()->setDevice($device)->setTargetUrl($url));
            }
        }

        return null;
    }

    /**
     * Hands a successful, resource-shaped payload to plugins before it is serialised.
     * Additive only: the response keeps its status code and shape, plugins just add fields.
     *
     * @param array<string, mixed> $payload
     */
    private function ok(string $operation, Request $request, array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        $event = new ApiResponseEvent($operation, $request, $payload);
        $this->dispatcher->dispatch($event);

        return $this->json($event->getPayload(), $status);
    }

    /** A plugin refused the write (LinkPersistingEvent) - report it like the other 422s, not as a 500. */
    private function rejected(LinkRejectedException $exception): JsonResponse
    {
        return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }
    }

    /**
     * Domains the caller may create links on: the user's (all active ones for admins),
     * narrowed to the token's domains if it is limited to some.
     *
     * @return list<Domain>
     */
    private function usableDomains(User $user): array
    {
        $usable = $this->domains->findUsableBy($user, $this->adminAccess->isAdmin($user));
        $token = $this->apiToken->get();

        return null === $token ? $usable : array_values(array_filter($usable, $token->allowsDomain(...)));
    }

    private function throttle(User $user): void
    {
        $limit = $this->apiLimiter->create('user-'.$user->getId())->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time(), 'Rate limit exceeded.');
        }
    }

    private function error(string $field, string $message): JsonResponse
    {
        return $this->json(['error' => 'validation_failed', 'violations' => [['field' => $field, 'message' => $message]]], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function violations(ConstraintViolationListInterface $violations): JsonResponse
    {
        $list = [];
        foreach ($violations as $violation) {
            $list[] = ['field' => self::snake($violation->getPropertyPath()), 'message' => (string) $violation->getMessage()];
        }

        return $this->json(['error' => 'validation_failed', 'violations' => $list], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private static function snake(string $path): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $path));
    }
}
