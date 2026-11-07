<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditLogger;
use App\Domain\HostRegistry;
use App\Entity\Domain;
use App\Form\DomainType;
use App\Repository\DomainRepository;
use App\Repository\LinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ADMIN_ACCESS')]
#[Route('/admin/domains')]
final class DomainController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DomainRepository $domains,
        private readonly HostRegistry $hosts,
        private readonly TranslatorInterface $translator,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'app_admin_domains', methods: ['GET'])]
    public function index(LinkRepository $links): Response
    {
        $rows = array_map(
            static fn (Domain $domain): array => ['domain' => $domain, 'links' => $links->countByDomain($domain)],
            $this->domains->findAllOrdered(),
        );

        return $this->render('admin/domain/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/new', name: 'app_admin_domain_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $domain = new Domain();

        return $this->handle($request, $this->createForm(DomainType::class, $domain), $domain);
    }

    #[Route('/{id}', name: 'app_admin_domain_edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Request $request, Domain $domain): Response
    {
        return $this->handle($request, $this->createForm(DomainType::class, $domain), $domain);
    }

    #[Route('/{id}/delete', name: 'app_admin_domain_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function delete(Request $request, Domain $domain, LinkRepository $links): Response
    {
        if (!$this->isCsrfTokenValid('domain-delete-'.$domain->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($links->countByDomain($domain) > 0) {
            $this->addFlash('danger', 'flash.domain_has_links');

            return $this->redirectToRoute('app_admin_domain_edit', ['id' => $domain->getId()]);
        }
        foreach ($domain->getUsers() as $user) {
            $user->removeDomain($domain);
        }
        $domainId = $domain->getId();
        $hostname = $domain->getHostname();
        $this->em->remove($domain);
        $this->em->flush();
        $this->audit->record(AuditLogger::DOMAIN_DELETED, ['hostname' => $hostname], targetType: 'domain', targetId: $domainId);
        $this->addFlash('success', 'flash.domain_deleted');

        return $this->redirectToRoute('app_admin_domains');
    }

    /** @param FormInterface<Domain> $form */
    private function handle(Request $request, FormInterface $form, Domain $domain): Response
    {
        $isNew = null === $domain->getId();
        // Captured before the form binds the request - the entity is mutated in place.
        $beforeHostname = $domain->getHostname();

        $form->handleRequest($request);
        if ($form->isSubmitted() && $this->hosts->isAdminHost($domain->getHostname())) {
            $form->get('hostname')->addError(new FormError($this->translator->trans('domain.hostname.is_admin', domain: 'validators')));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $this->em->persist($domain);
            }
            $this->em->flush();
            if ($domain->isDefault()) {
                $this->domains->clearDefaultExcept($domain);
            }
            if ($isNew) {
                $this->audit->record(AuditLogger::DOMAIN_CREATED, ['hostname' => $domain->getHostname()], targetType: 'domain', targetId: $domain->getId());
            } else {
                $context = ['hostname' => $domain->getHostname()];
                if ($beforeHostname !== $domain->getHostname()) {
                    $context['previous_hostname'] = $beforeHostname;
                }
                $this->audit->record(AuditLogger::DOMAIN_UPDATED, $context, targetType: 'domain', targetId: $domain->getId());
            }
            $this->addFlash('success', 'flash.domain_saved');

            return $this->redirectToRoute('app_admin_domains');
        }

        return $this->render('admin/domain/form.html.twig', ['form' => $form, 'domain' => $domain], new Response(
            status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        ));
    }
}
