<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit\AuditLogger;
use App\Entity\ResetToken;
use App\Form\ChangePasswordType;
use App\Mail\AccountMailer;
use App\Mail\DeferredMailer;
use App\Repository\ResetTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        // ?reauth=1 keeps the form reachable for users who are already signed in: the
        // profile page uses it to re-authenticate with a passkey (see _confirm_form).
        $reauth = $request->query->getBoolean('reauth');
        if ($this->isGranted('IS_AUTHENTICATED_FULLY') && !$reauth) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'reauth' => $reauth,
            'success_url' => $this->generateUrl($reauth ? 'app_profile_security' : 'app_dashboard'),
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Handled by the firewall.');
    }

    #[Route('/reset', name: 'app_reset_request', methods: ['GET', 'POST'])]
    public function requestReset(
        Request $request,
        UserRepository $users,
        AccountMailer $mailer,
        DeferredMailer $deferred,
        #[Autowire(service: 'limiter.password_reset')]
        RateLimiterFactoryInterface $passwordResetLimiter,
        #[Autowire(service: 'limiter.password_reset_email')]
        RateLimiterFactoryInterface $passwordResetEmailLimiter,
    ): Response {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('reset_request', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $email = (string) $request->request->get('email');
            $normalized = mb_strtolower(trim($email));
            // Per IP and per mailbox: the address alone is limited too, so many
            // IPs cannot flood one inbox.
            $limit = $passwordResetLimiter->create($request->getClientIp().'|'.$normalized)->consume();
            $emailLimit = $passwordResetEmailLimiter->create($normalized)->consume();
            $user = $users->findOneByEmail($email);
            if ($limit->isAccepted() && $emailLimit->isAccepted() && null !== $user && $user->isActive()) {
                // The mail goes out after the response; nothing here reveals
                // whether the address exists or how long the transport takes.
                $deferred->defer(static fn () => $mailer->sendAccountLink($user, ResetToken::PURPOSE_RESET));
            }

            // Same answer whether the address exists or not.
            $this->addFlash('success', 'flash.reset_sent');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_request.html.twig');
    }

    #[Route('/reset/{token}', name: 'app_reset', methods: ['GET', 'POST'])]
    #[Route('/invite/{token}', name: 'app_invite', methods: ['GET', 'POST'])]
    public function setPassword(
        Request $request,
        string $token,
        ResetTokenRepository $tokens,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
    ): Response {
        $resetToken = $tokens->findValid($token);
        if (null === $resetToken) {
            return $this->render('security/link_invalid.html.twig', response: new Response(status: Response::HTTP_GONE));
        }

        $user = $resetToken->getUser();
        $form = $this->createForm(ChangePasswordType::class, options: ['require_current' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));
            $tokens->removeAllFor($user);
            $em->flush();
            $this->audit->record(AuditLogger::PASSWORD_RESET, ['purpose' => $resetToken->getPurpose()], actor: $user, targetType: 'user', targetId: $user->getId());
            $this->addFlash('success', ResetToken::PURPOSE_INVITE === $resetToken->getPurpose() ? 'flash.invite_done' : 'flash.reset_done');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/set_password.html.twig', [
            'form' => $form,
            'user' => $user,
            'purpose' => $resetToken->getPurpose(),
        ]);
    }
}
