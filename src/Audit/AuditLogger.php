<?php

declare(strict_types=1);

namespace App\Audit;

use App\Entity\AuditEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Writes the audit trail. Everything runs synchronously in the request (there is no
 * queue), so the write is guarded: a failing audit entry must never break the user's
 * action - it is reported through the normal logger instead.
 *
 * Actions are stable machine keys ("auth.login", "link.deleted", ...), never translated
 * labels; the UI translates them via "audit.action.<key>". The context is a small
 * "what/where" payload and is filtered against a denylist before it is stored.
 */
final readonly class AuditLogger
{
    public const string LOGIN = 'auth.login';
    public const string LOGIN_FAILED = 'auth.login_failed';
    public const string LOGOUT = 'auth.logout';
    public const string TWO_FA_ENABLED = 'auth.2fa_enabled';
    public const string TWO_FA_DISABLED = 'auth.2fa_disabled';
    public const string BACKUP_CODES_REGENERATED = 'auth.backup_codes_regenerated';
    public const string PASSWORD_CHANGED = 'auth.password_changed';
    public const string PASSWORD_RESET = 'auth.password_reset';
    public const string PASSKEY_ADDED = 'auth.passkey_added';
    public const string PASSKEY_REMOVED = 'auth.passkey_removed';

    public const string TOKEN_CREATED = 'token.created';
    public const string TOKEN_REVOKED = 'token.revoked';

    public const string USER_CREATED = 'user.created';
    public const string USER_ROLES_CHANGED = 'user.roles_changed';
    public const string USER_ACTIVATED = 'user.activated';
    public const string USER_DEACTIVATED = 'user.deactivated';
    public const string USER_DELETED = 'user.deleted';

    public const string DOMAIN_CREATED = 'domain.created';
    public const string DOMAIN_UPDATED = 'domain.updated';
    public const string DOMAIN_DELETED = 'domain.deleted';

    public const string IP_RULE_ADDED = 'ip_rule.added';
    public const string IP_RULE_DELETED = 'ip_rule.deleted';

    public const string SETTINGS_CHANGED = 'settings.changed';

    public const string LINK_CREATED = 'link.created';
    public const string LINK_UPDATED = 'link.updated';
    public const string LINK_DELETED = 'link.deleted';

    /** Every action the app can produce; drives the filter select on the audit page. */
    public const array KNOWN_ACTIONS = [
        self::LOGIN,
        self::LOGIN_FAILED,
        self::LOGOUT,
        self::TWO_FA_ENABLED,
        self::TWO_FA_DISABLED,
        self::BACKUP_CODES_REGENERATED,
        self::PASSWORD_CHANGED,
        self::PASSWORD_RESET,
        self::PASSKEY_ADDED,
        self::PASSKEY_REMOVED,
        self::TOKEN_CREATED,
        self::TOKEN_REVOKED,
        self::USER_CREATED,
        self::USER_ROLES_CHANGED,
        self::USER_ACTIVATED,
        self::USER_DEACTIVATED,
        self::USER_DELETED,
        self::DOMAIN_CREATED,
        self::DOMAIN_UPDATED,
        self::DOMAIN_DELETED,
        self::IP_RULE_ADDED,
        self::IP_RULE_DELETED,
        self::SETTINGS_CHANGED,
        self::LINK_CREATED,
        self::LINK_UPDATED,
        self::LINK_DELETED,
    ];

    /**
     * Substrings that mark a context key (or a settings key) as carrying a secret. A key
     * that matches is dropped from the stored context instead of being written; "logo"
     * is included because it is a large data-URI blob whose value must not be duplicated
     * into the trail.
     *
     * @var list<string>
     */
    private const array SENSITIVE_TERMS = ['password', 'secret', 'code', 'token', 'hash', 'totp', 'logo'];

    public function __construct(
        private EntityManagerInterface $em,
        private RequestStack $requestStack,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Persists one audit entry and flushes it immediately.
     *
     * The actor falls back to the current security token; ip and user agent are read from
     * the current request. Never throws - a broken audit write is logged, not propagated.
     *
     * @param array<string, mixed> $context
     */
    public function record(string $action, array $context = [], ?User $actor = null, ?string $targetType = null, ?int $targetId = null): void
    {
        try {
            if (null === $actor) {
                $current = $this->security->getUser();
                if ($current instanceof User) {
                    $actor = $current;
                }
            }
            $request = $this->requestStack->getCurrentRequest();

            $entry = new AuditEntry(
                action: $action,
                actorLabel: $actor?->getEmail() ?? '',
                context: self::redactContext($context),
                actorId: $actor?->getId(),
                targetType: $targetType,
                targetId: $targetId,
                ip: $request?->getClientIp(),
                userAgent: self::truncate($request?->headers->get('User-Agent')),
            );

            $this->em->persist($entry);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Audit write failed for action "{action}"', [
                'action' => $action,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Whether a key (context or settings) looks like it carries a value that must not be
     * written verbatim. Used to drop context keys and to mask secret-like settings.
     */
    public static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SENSITIVE_TERMS as $term) {
            if (str_contains($key, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drops every entry whose key matches the denylist, so a password, token, 2FA code
     * or hash never reaches the database - even if a caller hands it in by mistake.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function redactContext(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if (self::isSensitiveKey($key)) {
                continue;
            }
            $safe[$key] = $value;
        }

        return $safe;
    }

    private static function truncate(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        return mb_substr($value, 0, 255);
    }
}
