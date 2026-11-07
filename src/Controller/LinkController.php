<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit\AuditLogger;
use App\Entity\Domain;
use App\Entity\Link;
use App\Entity\Tag;
use App\Entity\User;
use App\Form\LinkType;
use App\Link\LinkManager;
use App\Link\LinkRejectedException;
use App\Qr\QrCodeRenderer;
use App\Repository\ClickRepository;
use App\Repository\DomainRepository;
use App\Repository\LinkRepository;
use App\Repository\TagRepository;
use App\Security\AdminAccess;
use App\Security\Voter\LinkVoter;
use App\Stats\Period;
use App\Stats\StatsBuilder;
use App\Tag\TagManager;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/links')]
final class LinkController extends AbstractController
{
    private const int PER_PAGE = 25;

    /** Upper bound for one bulk request; anything larger is rejected with 422. */
    private const int BULK_MAX = 200;

    /** @var list<string> whitelisted sort keys; anything else falls back to created */
    private const array SORTS = ['created', 'clicks', 'last_click', 'target', 'code'];

    /** @var list<string> whitelisted sort directions; anything else falls back to desc */
    private const array DIRECTIONS = ['asc', 'desc'];

    /** @var list<string> whitelisted status filters; anything else means "all" */
    private const array STATUSES = ['live', 'inactive', 'expired'];

    /** @var list<string> whitelisted bulk actions */
    private const array BULK_ACTIONS = ['activate', 'deactivate', 'delete', 'move', 'tag_add', 'tag_remove'];

    public function __construct(
        private readonly LinkManager $manager,
        private readonly DomainRepository $domains,
        private readonly AdminAccess $adminAccess,
        private readonly TagRepository $tags,
        private readonly TagManager $tagManager,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'app_links', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, LinkRepository $links): Response
    {
        $search = trim((string) $request->query->get('q'));
        $domainId = $request->query->getInt('domain');
        $domain = $domainId > 0 ? $this->domains->find($domainId) : null;
        $isAdmin = $this->adminAccess->isAdmin($user);
        $onlyOwn = !$isAdmin || $request->query->getBoolean('mine');
        $page = max(1, $request->query->getInt('page', 1));
        // Unknown values silently fall back to the default order (created/desc).
        $sort = (string) $request->query->get('sort', 'created');
        $sort = \in_array($sort, self::SORTS, true) ? $sort : 'created';
        $dir = (string) $request->query->get('dir', 'desc');
        $dir = \in_array($dir, self::DIRECTIONS, true) ? $dir : 'desc';
        $status = (string) $request->query->get('status');
        $status = \in_array($status, self::STATUSES, true) ? $status : null;
        // Only the user's own tags filter the list; an unknown or foreign id means "all".
        $tagId = $request->query->getInt('tag');
        $tag = $tagId > 0 ? $this->tags->findOwned($user, $tagId) : null;

        $query = $links->searchQuery($user, $search, $domain, $onlyOwn, $sort, $dir, $status, $tag)
            ->setFirstResult(($page - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE);
        $paginator = new Paginator($query, fetchJoinCollection: false);

        return $this->render('link/index.html.twig', [
            'links' => $paginator,
            'total' => \count($paginator),
            'page' => $page,
            'pages' => max(1, (int) ceil(\count($paginator) / self::PER_PAGE)),
            'search' => $search,
            'domain' => $domain,
            'tag' => $tag,
            'tags' => $this->tags->findByOwner($user),
            'only_own' => $onlyOwn,
            'sort' => $sort,
            'dir' => $dir,
            'status' => $status,
            'statuses' => self::STATUSES,
            'domains' => $isAdmin ? $this->domains->findAllOrdered() : $this->domains->findUsableBy($user, false),
            'move_domains' => $this->domains->findUsableBy($user, $isAdmin),
        ]);
    }

    #[Route('/new', name: 'app_link_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user): Response
    {
        $usable = $this->domains->findUsableBy($user, $this->adminAccess->isAdmin($user));
        if ([] === $usable) {
            $this->addFlash('warning', 'flash.no_domains');

            return $this->redirectToRoute('app_dashboard');
        }

        $link = new Link($user);
        $link->setDomain($usable[0]);
        $this->manager->applyDefaults($link);

        // The bookmarklet and the popup flow hand over the target and title as query
        // parameters. Only take them over for the GET render and only when they look
        // plausible; a POST must keep using whatever the (validated) form carries.
        if ($request->isMethod('GET')) {
            $target = self::plausibleUrl($request->query->get('url'));
            if (null !== $target) {
                $link->setTargetUrl($target);
            }
            $title = self::plausibleTitle($request->query->get('title'));
            if (null !== $title) {
                $link->setTitle($title);
            }
        }

        // The dashboard quick form only posts target, domain and code; a full form would
        // read the missing "active" checkbox as unchecked.
        $full = !$request->isMethod('POST') || \array_key_exists('redirectType', $request->request->all('link'));
        $form = $this->createForm(LinkType::class, $link, ['domains' => $usable, 'full' => $full, 'tags' => $this->tags->findByOwner($user)]);

        return $this->handleForm($request, $form, $link, 'link/new.html.twig', 'flash.link_created');
    }

    /**
     * Confirmation card of the bookmarklet popup flow. Rendering a form here - never
     * creating a link from a GET - keeps the flow CSRF-protected.
     */
    #[Route('/quick', name: 'app_link_quick', methods: ['GET'])]
    public function quick(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->render('link/quick.html.twig', [
            'url' => self::plausibleUrl($request->query->get('url')),
            'title' => self::plausibleTitle($request->query->get('title')),
            'code' => self::plausibleCode($request->query->get('code')),
            'goto' => $request->query->getBoolean('goto'),
            'domain' => $this->domains->findUsableBy($user, $this->adminAccess->isAdmin($user))[0] ?? null,
        ]);
    }

    #[Route('/quick', name: 'app_link_quick_create', methods: ['POST'])]
    public function quickCreate(Request $request, #[CurrentUser] User $user, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('link-quick', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $usable = $this->domains->findUsableBy($user, $this->adminAccess->isAdmin($user));
        if ([] === $usable) {
            $this->addFlash('warning', 'flash.no_domains');

            return $this->redirectToRoute('app_dashboard');
        }

        $url = self::plausibleUrl($request->request->get('url'));
        $rawCode = $request->request->get('code');
        $code = \is_string($rawCode) ? trim($rawCode) : '';
        $goto = $request->request->getBoolean('goto');

        // This flow always uses the user's default (first usable) domain - no domain parameter.
        $link = new Link($user);
        $link->setDomain($usable[0]);
        if (null !== $url) {
            $link->setTargetUrl($url);
        }
        // The bookmarklet already knows the page title; only a link without one is looked up.
        $link->setTitle(self::plausibleTitle($request->request->get('title')));
        if ('' !== $code) {
            $link->setCode($code);
        }
        $this->manager->applyDefaults($link);

        $violations = $this->manager->validate($link);
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = $violation->getMessage();
        }

        if ([] === $messages) {
            try {
                $this->manager->save($link);
                $this->addFlash('success', $translator->trans('flash.link_quick_created', ['%url%' => $link->getShortUrl()]));

                return $goto
                    ? $this->redirect($link->getTargetUrl())
                    : $this->redirectToRoute('app_link_show', ['id' => $link->getId()]);
            } catch (LinkRejectedException $e) {
                $messages[] = $e->getMessage();
            }
        }

        return $this->render('link/quick.html.twig', [
            'url' => $url,
            'title' => self::plausibleTitle($request->request->get('title')),
            'code' => $code,
            'goto' => $goto,
            'domain' => $usable[0],
            'violations' => $messages,
        ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/bulk', name: 'app_links_bulk', methods: ['POST'])]
    public function bulk(Request $request, #[CurrentUser] User $user, LinkRepository $links): Response
    {
        if (!$this->isCsrfTokenValid('links-bulk', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $action = (string) $request->request->get('action');
        if (!\in_array($action, self::BULK_ACTIONS, true)) {
            throw new UnprocessableEntityHttpException('Unknown bulk action.');
        }

        $ids = $this->bulkIds($request);
        if (\count($ids) > self::BULK_MAX) {
            throw new UnprocessableEntityHttpException(\sprintf('At most %d links per bulk action.', self::BULK_MAX));
        }

        if ([] === $ids) {
            $this->addFlash('warning', 'bulk.none');

            return $this->redirectToRoute('app_links', $this->bulkFilters($request));
        }

        if ('move' === $action) {
            $target = $this->usableDomain($user, $request->request->getInt('domain'));
            if (null === $target) {
                $this->addFlash('warning', 'bulk.move_invalid');

                return $this->redirectToRoute('app_links', $this->bulkFilters($request));
            }
            [$changed, $denied, $conflicts] = $this->moveLinks($ids, $target, $links);
        } elseif ('tag_add' === $action || 'tag_remove' === $action) {
            // Only the user's own tag can be applied; a foreign or unknown id changes nothing.
            $tag = $this->tags->findOwned($user, $request->request->getInt('tag'));
            if (null === $tag) {
                $this->addFlash('warning', 'bulk.tag_invalid');

                return $this->redirectToRoute('app_links', $this->bulkFilters($request));
            }
            [$changed, $denied, $conflicts] = $this->applyTagAction($ids, $tag, 'tag_add' === $action, $links);
            if ($changed > 0) {
                // TagManager attaches/detaches in one flush and dispatches no link event, so the
                // audit trail gets one entry for the batch instead of one per link.
                $this->audit->record(
                    AuditLogger::LINK_TAGS_CHANGED,
                    ['tag' => $tag->getName(), 'added' => 'tag_add' === $action, 'count' => $changed],
                    targetType: 'tag',
                    targetId: $tag->getId(),
                );
            }
        } else {
            [$changed, $denied, $conflicts] = $this->applyToLinks($ids, $action, $links);
        }

        $this->addFlash($changed > 0 ? 'success' : 'warning', new TranslatableMessage('bulk.result', [
            '%changed%' => (string) $changed,
            '%denied%' => (string) $denied,
            '%conflicts%' => (string) $conflicts,
        ]));

        return $this->redirectToRoute('app_links', $this->bulkFilters($request));
    }

    #[Route('/{id}', name: 'app_link_show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[IsGranted(LinkVoter::MANAGE, 'link')]
    public function show(Request $request, Link $link, StatsBuilder $stats): Response
    {
        return $this->render('link/show.html.twig', [
            'link' => $link,
            'stats' => $stats->build(Period::lastDays($request->query->getInt('days', 30)), link: $link),
            'periods' => Period::CHOICES,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_link_edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    #[IsGranted(LinkVoter::MANAGE, 'link')]
    public function edit(Request $request, Link $link, #[CurrentUser] User $user): Response
    {
        $usable = $this->domains->findUsableBy($user, $this->adminAccess->isAdmin($user));
        if (null !== $link->getDomain() && !\in_array($link->getDomain(), $usable, true)) {
            // Keep the current domain selectable even if the assignment was revoked later.
            $usable[] = $link->getDomain();
        }
        $tags = $this->tags->findByOwner($user);
        foreach ($link->getTags() as $current) {
            // Keep the link's existing tags selectable (e.g. an admin editing somebody else's
            // link) so submitting the form does not silently detach them.
            if (!\in_array($current, $tags, true)) {
                $tags[] = $current;
            }
        }
        $form = $this->createForm(LinkType::class, $link, ['domains' => $usable, 'tags' => $tags]);

        return $this->handleForm($request, $form, $link, 'link/edit.html.twig', 'flash.link_saved');
    }

    #[Route('/{id}/delete', name: 'app_link_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted(LinkVoter::MANAGE, 'link')]
    public function delete(Request $request, Link $link): Response
    {
        if (!$this->isCsrfTokenValid('delete-link-'.$link->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->manager->delete($link);
        $this->addFlash('success', 'flash.link_deleted');

        return $this->redirectToRoute('app_links');
    }

    #[Route('/{id}/qr.{format}', name: 'app_link_qr', requirements: ['id' => Requirement::DIGITS, 'format' => 'svg|png'], methods: ['GET'])]
    #[IsGranted(LinkVoter::MANAGE, 'link')]
    public function qr(Request $request, Link $link, string $format, QrCodeRenderer $qr): Response
    {
        $png = 'png' === $format;
        $response = new Response(
            $png ? $qr->png($link->getShortUrl()) : $qr->svg($link->getShortUrl()),
            Response::HTTP_OK,
            ['Content-Type' => $png ? 'image/png' : 'image/svg+xml', 'Cache-Control' => 'private, max-age=300'],
        );
        if ($request->query->getBoolean('download')) {
            $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                \sprintf('qr-%s-%s.%s', $link->getDomain()?->getHostname(), $link->getCode(), $format),
            ));
        }

        return $response;
    }

    #[Route('/{id}/export.csv', name: 'app_link_export', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[IsGranted(LinkVoter::MANAGE, 'link')]
    public function export(Request $request, Link $link, ClickRepository $clicks): StreamedResponse
    {
        $period = Period::lastDays($request->query->getInt('days', 365));
        $response = new StreamedResponse(static function () use ($clicks, $link, $period): void {
            $out = fopen('php://output', 'w');
            if (false === $out) {
                return;
            }
            fputcsv($out, ['clicked_at', 'country', 'referrer', 'device', 'browser', 'os', 'language', 'bot'], escape: '');
            foreach ($clicks->iterateForExport($link, $period->from, $period->to) as $click) {
                fputcsv($out, [
                    $click->getClickedAt()->format(\DATE_ATOM),
                    $click->getCountryCode(),
                    $click->getReferrerHost(),
                    $click->getDeviceType(),
                    $click->getBrowser(),
                    $click->getOs(),
                    $click->getLanguage(),
                    $click->isBot() ? '1' : '0',
                ], escape: '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            \sprintf('clicks-%s-%s.csv', $link->getDomain()?->getHostname(), $link->getCode()),
        ));

        return $response;
    }

    /**
     * Only http(s) URLs up to 2000 characters are taken over; anything else (javascript:,
     * data:, a missing scheme, ...) is dropped instead of being reflected into the form.
     */
    private static function plausibleUrl(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ('' === $value || \strlen($value) > 2000) {
            return null;
        }
        $scheme = strtolower((string) parse_url($value, \PHP_URL_SCHEME));

        return \in_array($scheme, ['http', 'https'], true) ? $value : null;
    }

    private static function plausibleTitle(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ('' === $value || mb_strlen($value) > 255) {
            return null;
        }

        return $value;
    }

    /** Only prefill a code that already matches the format the server will accept. */
    private static function plausibleCode(mixed $value): string
    {
        if (!\is_string($value)) {
            return '';
        }
        $value = trim($value);

        return 1 === preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/', $value) ? $value : '';
    }

    /**
     * Positive, de-duplicated ids from the "ids[]" field.
     *
     * @return list<int>
     */
    private function bulkIds(Request $request): array
    {
        $ids = [];
        foreach ($request->request->all('ids') as $raw) {
            if (!\is_string($raw) && !\is_int($raw)) {
                continue;
            }
            $id = (int) $raw;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Rebuilds the list filters from the bulk form for the redirect. Only whitelisted
     * parameters survive, so a tampered form cannot turn this into an open redirect.
     *
     * @return array<string, string|int>
     */
    private function bulkFilters(Request $request): array
    {
        $return = $request->request->all('return');
        $params = [];

        $q = trim((string) ($return['q'] ?? ''));
        if ('' !== $q) {
            $params['q'] = $q;
        }
        $domain = (int) ($return['domain'] ?? 0);
        if ($domain > 0) {
            $params['domain'] = $domain;
        }
        if (!empty($return['mine'])) {
            $params['mine'] = '1';
        }
        $sort = (string) ($return['sort'] ?? '');
        if (\in_array($sort, self::SORTS, true)) {
            $params['sort'] = $sort;
        }
        $dir = (string) ($return['dir'] ?? '');
        if (\in_array($dir, self::DIRECTIONS, true)) {
            $params['dir'] = $dir;
        }
        $status = (string) ($return['status'] ?? '');
        if (\in_array($status, self::STATUSES, true)) {
            $params['status'] = $status;
        }
        $tag = (int) ($return['tag'] ?? 0);
        if ($tag > 0) {
            $params['tag'] = $tag;
        }
        $page = (int) ($return['page'] ?? 1);
        if ($page > 1) {
            $params['page'] = $page;
        }

        return $params;
    }

    /**
     * Adds or removes one tag on every permitted link. The ownership check stays authoritative
     * (foreign or missing links are counted as denied, never touched) and the whole run issues
     * a single flush - see TagManager::attach().
     *
     * @param list<int> $ids
     *
     * @return array{int, int, int} [changed, denied, conflicts]
     */
    private function applyTagAction(array $ids, Tag $tag, bool $add, LinkRepository $links): array
    {
        $permitted = [];
        $denied = 0;
        foreach ($ids as $id) {
            $link = $links->find($id);
            if (null === $link || !$this->isGranted(LinkVoter::MANAGE, $link)) {
                ++$denied;
                continue;
            }
            $permitted[] = $link;
        }

        return [$this->tagManager->attach($permitted, $tag, $add), $denied, 0];
    }

    /** The domain with that id if the user may use it (all active domains for admins), otherwise null. */
    private function usableDomain(User $user, int $domainId): ?Domain
    {
        foreach ($this->domains->findUsableBy($user, $this->adminAccess->isAdmin($user)) as $domain) {
            if ($domain->getId() === $domainId) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * Activate, deactivate or delete every permitted link.
     *
     * @param list<int> $ids
     *
     * @return array{int, int, int} [changed, denied, conflicts]
     */
    private function applyToLinks(array $ids, string $action, LinkRepository $links): array
    {
        $changed = 0;
        $denied = 0;
        foreach ($ids as $id) {
            $link = $links->find($id);
            if (null === $link || !$this->isGranted(LinkVoter::MANAGE, $link)) {
                // Missing or foreign links are counted and reported, never touched.
                ++$denied;
                continue;
            }
            if ('delete' === $action) {
                $this->manager->delete($link);
            } else {
                $wasActive = $link->isActive();
                $link->setActive('activate' === $action);
                try {
                    $this->manager->save($link);
                } catch (LinkRejectedException) {
                    // A plugin refused the write: leave the entity as it was and count this
                    // link as skipped instead of aborting the whole bulk action with a 500.
                    $link->setActive($wasActive);
                    ++$denied;
                    continue;
                }
            }
            ++$changed;
        }

        return [$changed, $denied, 0];
    }

    /**
     * Move every permitted link to $target, skipping ones whose code is already taken there
     * (the unique index would otherwise abort the request with a 500).
     *
     * @param list<int> $ids
     *
     * @return array{int, int, int} [changed, denied, conflicts]
     */
    private function moveLinks(array $ids, Domain $target, LinkRepository $links): array
    {
        $changed = 0;
        $denied = 0;
        $conflicts = 0;
        foreach ($ids as $id) {
            $link = $links->find($id);
            if (null === $link || !$this->isGranted(LinkVoter::MANAGE, $link)) {
                ++$denied;
                continue;
            }
            if ($link->getDomain()?->getId() === $target->getId() || $links->codeExists($target, $link->getCode())) {
                ++$conflicts;
                continue;
            }
            $previousDomain = $link->getDomain();
            $link->setDomain($target);
            try {
                $this->manager->save($link);
            } catch (LinkRejectedException) {
                // Undo the in-memory move so a later flush cannot persist a rejected change.
                $link->setDomain($previousDomain);
                ++$denied;
                continue;
            }
            ++$changed;
        }

        return [$changed, $denied, $conflicts];
    }

    /** @param FormInterface<Link> $form */
    private function handleForm(Request $request, FormInterface $form, Link $link, string $template, string $flash): Response
    {
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->manager->save($link);
                $this->addFlash('success', $flash);

                return $this->redirectToRoute('app_link_show', ['id' => $link->getId()]);
            } catch (LinkRejectedException $e) {
                // A plugin refused the write: surface its message as a form error and re-render
                // with the entered values, exactly like a validation error - never a 500.
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render($template, ['form' => $form, 'link' => $link], new Response(
            status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        ));
    }
}
