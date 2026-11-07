<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditLogger;
use App\Entity\ResetToken;
use App\Entity\User;
use App\Form\AdminUserType;
use App\Mail\AccountMailer;
use App\Repository\LinkRepository;
use App\Repository\UserRepository;
use App\Repository\WebauthnCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ADMIN_ACCESS')]
#[Route('/admin/users')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly AccountMailer $mailer,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'app_admin_users', methods: ['GET'])]
    public function index(LinkRepository $links, WebauthnCredentialRepository $credentials): Response
    {
        $rows = [];
        foreach ($this->users->findAllOrdered() as $user) {
            $rows[] = [
                'user' => $user,
                'links' => $links->countFor($user),
                'passkeys' => $credentials->countByUser($user),
            ];
        }

        return $this->render('admin/user/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = new User();
        $form = $this->createForm(AdminUserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($user);
            $this->em->flush();
            $this->audit->record(AuditLogger::USER_CREATED, ['email' => $user->getEmail(), 'admin' => $user->isAdmin()], targetType: 'user', targetId: $user->getId());
            $this->flashAccountLink($this->mailer->sendAccountLink($user, ResetToken::PURPOSE_INVITE));

            return $this->redirectToRoute('app_admin_user_edit', ['id' => $user->getId()]);
        }

        return $this->render('admin/user/form.html.twig', ['form' => $form, 'user' => $user], $this->status($form->isSubmitted()));
    }

    #[Route('/{id}', name: 'app_admin_user_edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user, #[CurrentUser] User $me): Response
    {
        $isSelf = $user->getId() === $me->getId();
        // Snapshot before the form binds the request: the form mutates the entity in place,
        // so afterwards the previous roles/active state would be gone.
        $beforeRoles = self::sortedRoles($user);
        $beforeActive = $user->isActive();

        $form = $this->createForm(AdminUserType::class, $user, ['is_self' => $isSelf]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $afterRoles = self::sortedRoles($user);
            if ($beforeRoles !== $afterRoles) {
                $this->audit->record(AuditLogger::USER_ROLES_CHANGED, ['from' => $beforeRoles, 'to' => $afterRoles], targetType: 'user', targetId: $user->getId());
            }
            if ($beforeActive !== $user->isActive()) {
                $this->audit->record($user->isActive() ? AuditLogger::USER_ACTIVATED : AuditLogger::USER_DEACTIVATED, targetType: 'user', targetId: $user->getId());
            }
            $this->addFlash('success', 'flash.user_saved');

            return $this->redirectToRoute('app_admin_user_edit', ['id' => $user->getId()]);
        }

        return $this->render('admin/user/form.html.twig', [
            'form' => $form,
            'user' => $user,
            'is_self' => $isSelf,
            'transfer_targets' => array_filter($this->users->findAllOrdered(), static fn (User $u): bool => $u->getId() !== $user->getId()),
        ], $this->status($form->isSubmitted()));
    }

    #[Route('/{id}/send-link', name: 'app_admin_user_send_link', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function sendLink(Request $request, User $user): Response
    {
        $this->assertCsrf($request, 'user-link-'.$user->getId());
        $purpose = $user->hasPassword() ? ResetToken::PURPOSE_RESET : ResetToken::PURPOSE_INVITE;
        $this->flashAccountLink($this->mailer->sendAccountLink($user, $purpose));

        return $this->redirectToRoute('app_admin_user_edit', ['id' => $user->getId()]);
    }

    #[Route('/{id}/reset-2fa', name: 'app_admin_user_reset_2fa', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function resetTwoFactor(Request $request, User $user): Response
    {
        $this->assertCsrf($request, 'user-2fa-'.$user->getId());
        $user->setTotpEnabled(false);
        $this->em->flush();
        $this->audit->record(AuditLogger::TWO_FA_DISABLED, ['via' => 'admin'], targetType: 'user', targetId: $user->getId());
        $this->addFlash('success', 'flash.user_2fa_reset');

        return $this->redirectToRoute('app_admin_user_edit', ['id' => $user->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_admin_user_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function delete(Request $request, User $user, #[CurrentUser] User $me, LinkRepository $links): Response
    {
        $this->assertCsrf($request, 'user-delete-'.$user->getId());
        if ($user->getId() === $me->getId()) {
            $this->addFlash('danger', 'flash.user_delete_self');

            return $this->redirectToRoute('app_admin_user_edit', ['id' => $user->getId()]);
        }

        $target = $this->users->find($request->request->getInt('transfer_to')) ?? $me;
        $this->em->wrapInTransaction(function () use ($links, $user, $target): void {
            $links->transferOwnership($user, $target);
            $this->em->remove($user);
        });
        // No ORM relation points at the user, so the entry outlives the account it describes.
        $this->audit->record(AuditLogger::USER_DELETED, ['email' => $user->getEmail()], targetType: 'user', targetId: $user->getId());
        $this->addFlash('success', 'flash.user_deleted');

        return $this->redirectToRoute('app_admin_users');
    }

    /** @param array{url: string, sent: bool} $result */
    private function flashAccountLink(array $result): void
    {
        $this->addFlash($result['sent'] ? 'success' : 'warning', $result['sent'] ? 'flash.account_link_sent' : 'flash.account_link_not_sent');
        // Admins can always copy the link, e.g. when no mailer is configured.
        $this->addFlash('link', $result['url']);
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * Effective roles (ROLE_USER always included), sorted so a set comparison is stable.
     *
     * @return list<string>
     */
    private static function sortedRoles(User $user): array
    {
        $roles = $user->getRoles();
        sort($roles);

        return $roles;
    }

    private function status(bool $submitted): Response
    {
        return new Response(status: $submitted ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }
}
