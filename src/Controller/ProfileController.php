<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit\AuditLogger;
use App\Entity\ApiToken;
use App\Entity\User;
use App\Entity\WebauthnCredential;
use App\Form\ChangePasswordType;
use App\Form\ProfileType;
use App\Qr\QrCodeRenderer;
use App\Repository\ApiTokenRepository;
use App\Repository\WebauthnCredentialRepository;
use App\Security\BackupCodeGenerator;
use App\Security\SecretBox;
use App\Security\SensitiveConfirmation;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/profile')]
final class ProfileController extends AbstractController
{
    private const string TOTP_SESSION_KEY = '_totp_setup_secret';
    private const string BACKUP_CODES_SESSION_KEY = '_backup_codes_once';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WebauthnCredentialRepository $credentials,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'app_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request, #[CurrentUser] User $user, UserPasswordHasherInterface $hasher): Response
    {
        $profileForm = $this->createForm(ProfileType::class, $user);
        $profileForm->handleRequest($request);
        if ($profileForm->isSubmitted() && $profileForm->isValid()) {
            $this->em->flush();
            $request->getSession()->set('_locale', $user->getLocale());
            $this->addFlash('success', 'flash.profile_saved');

            return $this->redirectToRoute('app_profile');
        }

        $passwordForm = $this->createForm(ChangePasswordType::class, options: ['require_current' => $user->hasPassword()]);
        $passwordForm->handleRequest($request);
        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            $user->setPassword($hasher->hashPassword($user, (string) $passwordForm->get('plainPassword')->getData()));
            $this->em->flush();
            $this->audit->record(AuditLogger::PASSWORD_CHANGED, actor: $user, targetType: 'user', targetId: $user->getId());
            $this->addFlash('success', 'flash.password_changed');

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/index.html.twig', [
            'profile_form' => $profileForm,
            'password_form' => $passwordForm,
        ]);
    }

    #[Route('/security', name: 'app_profile_security', methods: ['GET'])]
    public function security(Request $request, #[CurrentUser] User $user, SensitiveConfirmation $confirmation): Response
    {
        $passkeys = $this->credentials->findByUser($user);

        return $this->render('profile/security.html.twig', [
            'passkeys' => $passkeys,
            'has_passkeys' => [] !== $passkeys,
            'backup_codes' => $request->getSession()->remove(self::BACKUP_CODES_SESSION_KEY),
            'sensitive_confirmed' => $confirmation->isConfirmed($request->getSession()),
        ]);
    }

    /**
     * Re-authentication in front of sensitive profile actions: the password for users
     * that have one, otherwise a TOTP code. Opens a five minute window.
     */
    #[Route('/confirm', name: 'app_profile_confirm', methods: ['GET', 'POST'])]
    public function confirm(
        Request $request,
        #[CurrentUser] User $user,
        UserPasswordHasherInterface $hasher,
        TotpAuthenticatorInterface $totp,
        SensitiveConfirmation $confirmation,
        #[Autowire(service: 'limiter.profile_confirm')]
        RateLimiterFactoryInterface $confirmLimiter,
    ): Response {
        // Whitelisted targets only: no open redirect through the query string.
        $requested = $request->isMethod('POST') ? $request->request->get('target') : $request->query->get('target');
        $target = 'tokens' === $requested ? 'tokens' : 'security';
        // Passkey-only accounts without TOTP have nothing to verify against; they have to
        // set a password (or enable TOTP) first, otherwise the window would be free.
        $canConfirm = $user->hasPassword() || $user->isTotpAuthenticationEnabled();

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('profile-confirm', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            // A hijacked session must not be able to guess the password (or the six-digit
            // TOTP code) here: the confirmation form is throttled per account.
            if (!$confirmLimiter->create('user-'.$user->getId())->consume()->isAccepted()) {
                return $this->render('profile/confirm.html.twig', [
                    'target' => $target,
                    'has_passkeys' => $this->credentials->countByUser($user) > 0,
                    'error' => 'profile.confirm_too_many',
                ], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
            }
            if (!$canConfirm) {
                throw $this->createAccessDeniedException('This account has no credential to confirm with.');
            }
            $verified = $user->hasPassword()
                ? $hasher->isPasswordValid($user, (string) $request->request->get('password'))
                : $totp->checkCode($user, preg_replace('/\D/', '', (string) $request->request->get('code')) ?? '');

            if ($verified) {
                $confirmation->confirm($request->getSession());
                $this->addFlash('success', 'flash.sensitive_confirmed');

                return $this->redirectToRoute($this->routeForTarget($target));
            }
            $error = 'profile.confirm_failed';
        }

        return $this->render('profile/confirm.html.twig', [
            'target' => $target,
            'has_passkeys' => $this->credentials->countByUser($user) > 0,
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/totp', name: 'app_profile_totp_setup', methods: ['GET', 'POST'])]
    public function totpSetup(
        Request $request,
        #[CurrentUser] User $user,
        TotpAuthenticatorInterface $totp,
        SecretBox $secretBox,
    ): Response {
        if ($user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('app_profile_security');
        }

        $session = $request->getSession();
        $secret = $session->get(self::TOTP_SESSION_KEY);
        if (!\is_string($secret) || $request->query->getBoolean('new')) {
            $secret = $totp->generateSecret();
            $session->set(self::TOTP_SESSION_KEY, $secret);
        }
        $candidate = self::totpCandidate($user, $secret);

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('totp-setup', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            if ($totp->checkCode($candidate, preg_replace('/\D/', '', (string) $request->request->get('code')) ?? '')) {
                $codes = self::generateBackupCodes();
                $user->setTotpSecret($secret, $secretBox->encrypt($secret))
                    ->setTotpEnabled(true)
                    ->replaceBackupCodes($codes);
                $this->em->flush();
                $this->audit->record(AuditLogger::TWO_FA_ENABLED, actor: $user, targetType: 'user', targetId: $user->getId());
                $session->remove(self::TOTP_SESSION_KEY);
                $session->set(self::BACKUP_CODES_SESSION_KEY, $codes);
                $this->addFlash('success', 'flash.totp_enabled');

                return $this->redirectToRoute('app_profile_security');
            }
            $error = 'totp.code_wrong';
        }

        return $this->render('profile/totp_setup.html.twig', [
            'secret' => $secret,
            'qr_content' => $totp->getQRContent($candidate),
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/totp/qr.svg', name: 'app_profile_totp_qr', methods: ['GET'])]
    public function totpQr(Request $request, #[CurrentUser] User $user, TotpAuthenticatorInterface $totp, QrCodeRenderer $qr): Response
    {
        $secret = $request->getSession()->get(self::TOTP_SESSION_KEY);
        if (!\is_string($secret) || $user->isTotpAuthenticationEnabled()) {
            throw $this->createNotFoundException();
        }

        return new Response(
            $qr->svg($totp->getQRContent(self::totpCandidate($user, $secret))),
            Response::HTTP_OK,
            ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'no-store, private'],
        );
    }

    /** The not yet confirmed TOTP setup, without touching the stored user. */
    private static function totpCandidate(User $user, string $secret): TwoFactorInterface
    {
        return new readonly class($user->getEmail(), $secret) implements TwoFactorInterface {
            public function __construct(private string $name, private string $secret)
            {
            }

            public function isTotpAuthenticationEnabled(): bool
            {
                return true;
            }

            public function getTotpAuthenticationUsername(): string
            {
                return $this->name;
            }

            public function getTotpAuthenticationConfiguration(): TotpConfigurationInterface
            {
                return new TotpConfiguration($this->secret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
            }
        };
    }

    #[Route('/totp/disable', name: 'app_profile_totp_disable', methods: ['POST'])]
    public function totpDisable(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('totp-disable', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $user->setTotpEnabled(false);
        $this->em->flush();
        $this->audit->record(AuditLogger::TWO_FA_DISABLED, actor: $user, targetType: 'user', targetId: $user->getId());
        $this->addFlash('success', 'flash.totp_disabled');

        return $this->redirectToRoute('app_profile_security');
    }

    #[Route('/totp/backup-codes', name: 'app_profile_totp_backup_codes', methods: ['POST'])]
    public function backupCodes(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('backup-codes', (string) $request->request->get('_token')) || !$user->isTotpAuthenticationEnabled()) {
            throw $this->createAccessDeniedException();
        }
        $codes = self::generateBackupCodes();
        $user->replaceBackupCodes($codes);
        $this->em->flush();
        $this->audit->record(AuditLogger::BACKUP_CODES_REGENERATED, actor: $user, targetType: 'user', targetId: $user->getId());
        $request->getSession()->set(self::BACKUP_CODES_SESSION_KEY, $codes);

        return $this->redirectToRoute('app_profile_security');
    }

    #[Route('/passkeys/{id}/rename', name: 'app_profile_passkey_rename', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function renamePasskey(Request $request, WebauthnCredential $credential, #[CurrentUser] User $user): Response
    {
        $this->assertOwnCredential($request, $credential, $user, 'passkey-rename-');
        $name = trim((string) $request->request->get('name'));
        if ('' !== $name) {
            $credential->setName($name);
            $this->em->flush();
        }

        return $this->redirectToRoute('app_profile_security');
    }

    #[Route('/passkeys/{id}/delete', name: 'app_profile_passkey_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function deletePasskey(Request $request, WebauthnCredential $credential, #[CurrentUser] User $user): Response
    {
        $this->assertOwnCredential($request, $credential, $user, 'passkey-delete-');
        if (!$user->hasPassword() && $this->credentials->countByUser($user) <= 1) {
            $this->addFlash('danger', 'flash.passkey_last');

            return $this->redirectToRoute('app_profile_security');
        }
        $this->em->remove($credential);
        $this->em->flush();
        $this->audit->record(AuditLogger::PASSKEY_REMOVED, ['name' => $credential->getName()], actor: $user, targetType: 'user', targetId: $user->getId());
        $this->addFlash('success', 'flash.passkey_deleted');

        return $this->redirectToRoute('app_profile_security');
    }

    #[Route('/tokens', name: 'app_profile_tokens', methods: ['GET', 'POST'])]
    public function tokens(Request $request, #[CurrentUser] User $user, ApiTokenRepository $tokens, SensitiveConfirmation $confirmation): Response
    {
        $plain = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('api-token-create', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $name = mb_substr(trim((string) $request->request->get('name')), 0, 100);
            $days = $request->request->getInt('days');
            [$token, $plain] = ApiToken::issue(
                $user,
                '' === $name ? 'API' : $name,
                $days > 0 ? new \DateTimeImmutable(\sprintf('+%d days', $days)) : null,
            );
            $this->em->persist($token);
            $this->em->flush();
            $this->audit->record(AuditLogger::TOKEN_CREATED, ['name' => $token->getName()], actor: $user, targetType: 'api_token', targetId: $token->getId());
        }

        return $this->render('profile/tokens.html.twig', [
            'tokens' => $tokens->findByUser($user),
            'plain_token' => $plain,
            'has_passkeys' => $this->credentials->countByUser($user) > 0,
            'sensitive_confirmed' => $confirmation->isConfirmed($request->getSession()),
        ]);
    }

    #[Route('/tokens/{id}/delete', name: 'app_profile_token_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function deleteToken(Request $request, ApiToken $token, #[CurrentUser] User $user): Response
    {
        if ($token->getUser()->getId() !== $user->getId() || !$this->isCsrfTokenValid('api-token-delete-'.$token->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->em->remove($token);
        $this->em->flush();
        $this->audit->record(AuditLogger::TOKEN_REVOKED, ['name' => $token->getName()], actor: $user, targetType: 'api_token', targetId: $token->getId());
        $this->addFlash('success', 'flash.token_deleted');

        return $this->redirectToRoute('app_profile_tokens');
    }

    /** @return list<string> 10 codes like "k7m2-9qpx-t3vw" */
    private static function generateBackupCodes(): array
    {
        return BackupCodeGenerator::generate();
    }

    private function routeForTarget(string $target): string
    {
        return 'tokens' === $target ? 'app_profile_tokens' : 'app_profile_security';
    }

    private function assertOwnCredential(Request $request, WebauthnCredential $credential, User $user, string $csrfPrefix): void
    {
        if ($credential->getUser()->getId() !== $user->getId() || !$this->isCsrfTokenValid($csrfPrefix.$credential->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
