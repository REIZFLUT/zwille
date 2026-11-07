<?php

declare(strict_types=1);

namespace App\EventListener\Audit;

use App\Audit\AuditLogger;
use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Authentication trail. Hooks the firewall's own events instead of sprinkling calls over
 * the login/logout controllers: success, failure and logout all flow through here.
 *
 * 2FA, password, passkey and API-token changes have no event, so those live as explicit
 * {@see AuditLogger} calls in the controllers that own them.
 */
final readonly class AuditAuthListener
{
    public function __construct(private AuditLogger $audit)
    {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ('main' !== $event->getFirewallName()) {
            return;
        }
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $this->audit->record(AuditLogger::LOGIN, actor: $user, targetType: 'user', targetId: $user->getId());
    }

    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if ('main' !== $event->getFirewallName()) {
            return;
        }
        // Nobody is authenticated here, so the attempted address goes into the context.
        $email = trim((string) $event->getRequest()->request->get('email'));

        $this->audit->record(AuditLogger::LOGIN_FAILED, '' === $email ? [] : ['email' => $email]);
    }

    #[AsEventListener(event: LogoutEvent::class)]
    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        $this->audit->record(AuditLogger::LOGOUT, actor: $user instanceof User ? $user : null);
    }
}
