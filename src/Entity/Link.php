<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LinkRepository;
use App\Validator\ShortCode;
use App\Validator\TargetUrl;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: LinkRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_link_domain_code', columns: ['domain_id', 'code'])]
#[ORM\Index(name: 'idx_link_owner', columns: ['owner_id'])]
#[UniqueEntity(fields: ['domain', 'code'], message: 'link.code.taken', errorPath: 'code')]
class Link
{
    public const array REDIRECT_TYPES = [302, 301, 307, 308];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Domain $domain = null;

    /** Lowercase path segment after the domain, unique per domain. */
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[ShortCode]
    private string $code = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $owner;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[TargetUrl]
    private string $targetUrl = '';

    /** Internal note: never serialised by the API and never shown on a redirect page. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $notes = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Choice(choices: self::REDIRECT_TYPES)]
    private int $redirectType = 302;

    #[ORM\Column]
    private bool $active = true;

    /** null means immediately live; a future value schedules the link. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    /**
     * Where to send a visitor when the link cannot redirect right now (inactive, scheduled,
     * exhausted or expired). http(s) only; null means the notice page is shown instead.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[TargetUrl]
    private ?string $fallbackUrl = null;

    /** Human clicks (bots excluded), maintained by the ClickRecorder. */
    #[ORM\Column]
    private int $clickCount = 0;

    /** null means unlimited; otherwise the link stops once clickCount reaches this value. */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive(message: 'link.max_clicks.min')]
    private ?int $maxClicks = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastClickAt = null;

    /** @var Collection<int, LinkLocalisation> */
    #[ORM\OneToMany(targetEntity: LinkLocalisation::class, mappedBy: 'link', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['language' => \SortDirection::Ascending])]
    #[Assert\Valid]
    #[Assert\Unique(fields: ['language'], message: 'localisation.language.duplicate', normalizer: [self::class, 'localisationKey'])]
    private Collection $localisations;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'links')]
    #[ORM\JoinTable(name: 'link_tag')]
    #[ORM\JoinColumn(name: 'link_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $tags;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner)
    {
        $this->owner = $owner;
        $this->localisations = new ArrayCollection();
        $this->tags = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomain(): ?Domain
    {
        return $this->domain;
    }

    public function setDomain(?Domain $domain): static
    {
        $this->domain = $domain;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = mb_strtolower(trim((string) $code, " \t\n\r\0\x0B/"));

        return $this;
    }

    public function getShortUrl(string $scheme = 'https'): string
    {
        return \sprintf('%s://%s/%s', $scheme, $this->domain?->getHostname() ?? '', $this->code);
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getTargetUrl(): string
    {
        return $this->targetUrl;
    }

    public function setTargetUrl(?string $targetUrl): static
    {
        $this->targetUrl = trim((string) $targetUrl);

        return $this;
    }

    public function getFallbackUrl(): ?string
    {
        return $this->fallbackUrl;
    }

    public function setFallbackUrl(?string $fallbackUrl): static
    {
        $this->fallbackUrl = '' === trim((string) $fallbackUrl) ? null : trim((string) $fallbackUrl);

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = '' === trim((string) $notes) ? null : trim((string) $notes);

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = '' === trim((string) $title) ? null : trim((string) $title);

        return $this;
    }

    public function getRedirectType(): int
    {
        return $this->redirectType;
    }

    public function setRedirectType(int $redirectType): static
    {
        $this->redirectType = $redirectType;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    /** Active, not expired and on an active domain. */
    public function isLive(?\DateTimeImmutable $now = null): bool
    {
        return $this->active && !$this->isExpired($now) && true === $this->domain?->isActive();
    }

    public function getClickCount(): int
    {
        return $this->clickCount;
    }

    public function getMaxClicks(): ?int
    {
        return $this->maxClicks;
    }

    public function setMaxClicks(?int $maxClicks): static
    {
        $this->maxClicks = $maxClicks;

        return $this;
    }

    public function getLastClickAt(): ?\DateTimeImmutable
    {
        return $this->lastClickAt;
    }

    /** @return Collection<int, LinkLocalisation> */
    public function getLocalisations(): Collection
    {
        return $this->localisations;
    }

    public function addLocalisation(LinkLocalisation $localisation): static
    {
        if (!$this->localisations->contains($localisation)) {
            $this->localisations->add($localisation);
            $localisation->setLink($this);
        }

        return $this;
    }

    public function removeLocalisation(LinkLocalisation $localisation): static
    {
        $this->localisations->removeElement($localisation);

        return $this;
    }

    /** @return array<string, string> language => target url */
    public function getLocalisedTargets(): array
    {
        $targets = [];
        foreach ($this->localisations as $localisation) {
            $targets[$localisation->getLanguage()] = $localisation->getTargetUrl();
        }

        return $targets;
    }

    /**
     * Normaliser for the Assert\Unique constraint: the collection holds objects, while
     * Unique compares array elements, so reduce each row to the field it looks at.
     *
     * @return array{language: string}
     */
    public static function localisationKey(LinkLocalisation $localisation): array
    {
        return ['language' => $localisation->getLanguage()];
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Tag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(Tag $tag): static
    {
        $this->tags->removeElement($tag);

        return $this;
    }

    /** @param iterable<Tag> $tags */
    public function setTags(iterable $tags): static
    {
        $this->tags = new ArrayCollection();
        foreach ($tags as $tag) {
            $this->addTag($tag);
        }

        return $this;
    }

    public function hasTag(Tag $tag): bool
    {
        return $this->tags->contains($tag);
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
