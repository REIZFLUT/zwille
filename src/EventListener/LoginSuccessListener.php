<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Security\SensitiveConfirmation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Webauthn\Bundle\Security\Http\Authenticator\WebauthnAuthenticator;

#[AsEventListener]
final readonly class LoginSuccessListener
{
    /** Session key with the factor that authenticated this session. */
    public const string SESSION_AUTH_METHOD = '_zwille_auth_method';
    public const string METHOD_PASSWORD = 'password';
    public const string METHOD_PASSKEY = 'passkey';

    /** Route names of the passkey login (options/result), see webauthn_routes.yaml. */
    private const string PASSKEY_LOGIN_ROUTE_PREFIX = 'webauthn.controller.security.';

    public function __construct(
        private EntityManagerInterface $em,
        private SensitiveConfirmation $confirmation,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User || 'main' !== $event->getFirewallName()) {
            return;
        }
        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->em->flush();

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }
        $session = $request->getSession();
        $method = self::methodFor($event);
        // Remember how the user signed in: a password login must not count as
        // a second factor just because a passkey is registered.
        $session->set(self::SESSION_AUTH_METHOD, $method);

        if (self::METHOD_PASSKEY === $method) {
            // A passkey sign-in is a fresh strong authentication, so it also opens the
            // confirmation window for sensitive profile actions. Accounts without a
            // password (and without TOTP) have no other way to confirm at all.
            $this->confirmation->confirm($session);
        }

        if (null !== $user->getLocale()) {
            $session->set('_locale', $user->getLocale());
        }
    }

    /** Passkey login is recognised by its authenticator, and by the route it posted to. */
    private static function methodFor(LoginSuccessEvent $event): string
    {
        if ($event->getAuthenticator() instanceof WebauthnAuthenticator) {
            return self::METHOD_PASSKEY;
        }

        return str_starts_with((string) $event->getRequest()->attributes->get('_route'), self::PASSKEY_LOGIN_ROUTE_PREFIX)
            ? self::METHOD_PASSKEY
            : self::METHOD_PASSWORD;
    }
}
