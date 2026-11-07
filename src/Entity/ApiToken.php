<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ApiTokenRepository;
use App\Security\ApiScope;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Access token for the REST API. Only the sha256 hash is stored; the plain token is shown
 * once on creation.
 *
 * Two kinds exist:
 * - personal ("emsl_…"): acts exactly like its user, with every scope;
 * - agent ("emsa_…"): for AI agents and other automation. It never has admin rights, always
 *   expires, carries only the scopes chosen when it was issued and can additionally be
 *   limited to domains, target hosts and client addresses.
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
class ApiToken
{
    public const string PREFIX = 'emsl_';
    public const string AGENT_PREFIX = 'emsa_';

    public const string KIND_PERSONAL = 'personal';
    public const string KIND_AGENT = 'agent';

    /** Lifetimes offered for agent tokens; an agent token can never be unlimited. */
    public const array AGENT_LIFETIMES = [7, 30, 90];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    /** First characters of the token, to recognise it in the list. */
    #[ORM\Column(length: 16)]
    private string $hint;

    #[ORM\Column(length: 16, options: ['default' => self::KIND_PERSONAL])]
    private string $kind = self::KIND_PERSONAL;

    /**
     * Granted scopes; null means "all" (personal tokens).
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $scopes = null;

    /**
     * Domains the token may work on; empty means every domain of its user.
     *
     * @var Collection<int, Domain>
     */
    #[ORM\ManyToMany(targetEntity: Domain::class)]
    #[ORM\JoinTable(name: 'api_token_domain')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $domains;

    /**
     * Hosts link targets may point to (an entry also allows its subdomains); null or empty
     * means any target.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $targetHosts = null;

    /**
     * Client addresses or CIDR ranges the token may be used from; null or empty means any.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $allowedIps = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    private function __construct(User $user, string $name, string $plainToken, ?\DateTimeImmutable $expiresAt)
    {
        $this->user = $user;
        $this->name = $name;
        $this->tokenHash = self::hash($plainToken);
        $this->hint = substr($plainToken, 0, 12);
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->domains = new ArrayCollection();
    }

    /** @return array{ApiToken, string} the entity and the plain token (show it once) */
    public static function issue(User $user, string $name, ?\DateTimeImmutable $expiresAt = null): array
    {
        $plain = self::PREFIX.bin2hex(random_bytes(24));

        return [new self($user, $name, $plain, $expiresAt), $plain];
    }

    /**
     * Issues an agent token. Unknown scopes are dropped; the caller validates the input
     * beforehand and reports what it rejected.
     *
     * @param list<string>     $scopes
     * @param iterable<Domain> $domains
     * @param list<string>     $targetHosts
     * @param list<string>     $allowedIps
     *
     * @return array{ApiToken, string} the entity and the plain token (show it once)
     */
    public static function issueForAgent(User $user, string $name, int $lifetimeDays, array $scopes, iterable $domains = [], array $targetHosts = [], array $allowedIps = []): array
    {
        if (!\in_array($lifetimeDays, self::AGENT_LIFETIMES, true)) {
            throw new \InvalidArgumentException(\sprintf('An agent token must expire after %s days.', implode(', ', self::AGENT_LIFETIMES)));
        }
        $scopes = array_values(array_intersect(ApiScope::ALL, $scopes));
        if ([] === $scopes) {
            throw new \InvalidArgumentException('An agent token needs at least one scope.');
        }

        $plain = self::AGENT_PREFIX.bin2hex(random_bytes(24));
        $token = new self($user, $name, $plain, new \DateTimeImmutable(\sprintf('+%d days', $lifetimeDays)));
        $token->kind = self::KIND_AGENT;
        $token->scopes = $scopes;
        foreach ($domains as $domain) {
            if (!$token->domains->contains($domain)) {
                $token->domains->add($domain);
            }
        }
        $token->targetHosts = [] === $targetHosts ? null : array_values(array_unique(array_map(self::normalizeHost(...), $targetHosts)));
        $token->allowedIps = [] === $allowedIps ? null : array_values(array_unique($allowedIps));

        return [$token, $plain];
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /** "*.Example.com." and "example.com" both mean "example.com and its subdomains". */
    public static function normalizeHost(string $host): string
    {
        $host = mb_strtolower(trim($host));
        if (str_starts_with($host, '*.')) {
            $host = substr($host, 2);
        }

        return trim($host, '.');
    }

    public static function isValidHostPattern(string $host): bool
    {
        $host = self::normalizeHost($host);

        return '' !== $host && \strlen($host) <= 253
            && 1 === preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getHint(): string
    {
        return $this->hint;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function isAgent(): bool
    {
        return self::KIND_AGENT === $this->kind;
    }

    /** @return list<string> */
    public function getScopes(): array
    {
        return $this->scopes ?? ApiScope::ALL;
    }

    public function hasScope(string $scope): bool
    {
        return \in_array($scope, $this->getScopes(), true);
    }

    /** @return Collection<int, Domain> */
    public function getDomains(): Collection
    {
        return $this->domains;
    }

    public function restrictsDomains(): bool
    {
        return !$this->domains->isEmpty();
    }

    public function allowsDomain(?Domain $domain): bool
    {
        if (!$this->restrictsDomains()) {
            return true;
        }
        if (null === $domain) {
            return false;
        }
        foreach ($this->domains as $allowed) {
            if ($allowed->getId() === $domain->getId()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function getTargetHosts(): array
    {
        return $this->targetHosts ?? [];
    }

    public function allowsTargetHost(string $host): bool
    {
        if ([] === $this->getTargetHosts()) {
            return true;
        }
        $host = self::normalizeHost($host);
        foreach ($this->getTargetHosts() as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function getAllowedIps(): array
    {
        return $this->allowedIps ?? [];
    }

    public function allowsIp(?string $ip): bool
    {
        if ([] === $this->getAllowedIps()) {
            return true;
        }

        return null !== $ip && IpUtils::checkIp($ip, $this->getAllowedIps());
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= new \DateTimeImmutable();
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function revoke(): void
    {
        $this->revokedAt ??= new \DateTimeImmutable();
    }

    /** Neither expired nor revoked (the user's own state and the kill switch are checked on use). */
    public function isUsable(): bool
    {
        return !$this->isExpired() && !$this->isRevoked();
    }
}
