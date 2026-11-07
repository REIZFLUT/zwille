<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TagRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A per-user label for grouping links (campaigns). Belongs to exactly one owner;
 * uniqueness is enforced on the normalised name so SQLite (case-sensitive) and
 * MySQL/MariaDB (case-insensitive by default) agree - see App\Repository\UserRepository.
 */
#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_tag_owner_name', columns: ['owner_id', 'normalized_name'])]
#[ORM\Index(name: 'idx_tag_owner', columns: ['owner_id'])]
#[UniqueEntity(fields: ['owner', 'normalizedName'], message: 'tag.name.taken')]
final class Tag implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private User $owner;

    /** Display form, trimmed exactly as entered. */
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank(message: 'tag.name.blank')]
    #[Assert\Length(max: 64)]
    private string $name = '';

    /** Lowercased, trimmed name; the value the unique index compares. */
    #[ORM\Column(length: 64)]
    private string $normalizedName = '';

    /** @var Collection<int, Link> */
    #[ORM\ManyToMany(targetEntity: Link::class, mappedBy: 'tags')]
    private Collection $links;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $owner, string $name = '')
    {
        $this->owner = $owner;
        $this->links = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->setName($name);
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getName(): string
    {
        return $this->name;
    }

    /** Normalisation lives here so the display name and the compared key never drift apart. */
    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);
        $this->normalizedName = mb_strtolower($this->name);

        return $this;
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }

    /** @return Collection<int, Link> */
    public function getLinks(): Collection
    {
        return $this->links;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
