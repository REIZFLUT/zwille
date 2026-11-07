<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of the audit trail: who did what, when, and to which object.
 *
 * Deliberately has NO ORM relation to {@see User}. The trail must survive user changes:
 * deleting or renaming an account must neither remove nor null out its history, so the
 * actor is frozen as a plain id plus the label (e-mail) it had at the time of the action.
 * The context array is a small payload ("what/where") and must never carry passwords,
 * tokens, hashes or 2FA codes - that filtering lives in {@see \App\Audit\AuditLogger}.
 */
#[ORM\Entity(repositoryClass: AuditRepository::class)]
#[ORM\Index(name: 'idx_audit_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_audit_action', columns: ['action'])]
#[ORM\Index(name: 'idx_audit_actor', columns: ['actor_id'])]
class AuditEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /** Stable machine key such as "auth.login" or "link.deleted"; never a translated label. */
    #[ORM\Column(length: 64)]
    private string $action;

    /** Id of the acting user at the time, or null for anonymous actions (e.g. a failed login). */
    #[ORM\Column(nullable: true)]
    private ?int $actorId;

    /** The actor as it read at the time (e-mail), so the trail survives the user's deletion. */
    #[ORM\Column(length: 180)]
    private string $actorLabel;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $targetType;

    #[ORM\Column(nullable: true)]
    private ?int $targetId;

    /** @var array<string, mixed> small payload only: what/where, never secrets */
    #[ORM\Column(type: Types::JSON)]
    private array $context;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent;

    /** @param array<string, mixed> $context */
    public function __construct(
        string $action,
        string $actorLabel,
        array $context = [],
        ?int $actorId = null,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
        $this->action = $action;
        $this->actorLabel = $actorLabel;
        $this->context = $context;
        $this->actorId = $actorId;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getActorId(): ?int
    {
        return $this->actorId;
    }

    public function getActorLabel(): string
    {
        return $this->actorLabel;
    }

    public function getTargetType(): ?string
    {
        return $this->targetType;
    }

    public function getTargetId(): ?int
    {
        return $this->targetId;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }
}
