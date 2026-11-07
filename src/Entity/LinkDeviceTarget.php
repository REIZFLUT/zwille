<?php

declare(strict_types=1);

namespace App\Entity;

use App\Validator\TargetUrl;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Device-specific target of a link: an iOS, Android or desktop visitor (bots and anything
 * unrecognised count as desktop) is sent to its own URL. At most one row per device and link
 * (uniq_device_target_link_device).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_device_target_link_device', columns: ['link_id', 'device'])]
#[ORM\Index(name: 'idx_device_target_link', columns: ['link_id'])]
class LinkDeviceTarget
{
    /** The device keys accepted in the form, the API and the resolver. */
    public const array DEVICES = ['ios', 'android', 'desktop'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'deviceTargets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Link $link = null;

    /** One of ios, android, desktop. */
    #[ORM\Column(length: 16)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::DEVICES, message: 'device_targets.device.invalid')]
    private string $device = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[TargetUrl]
    private string $targetUrl = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLink(): ?Link
    {
        return $this->link;
    }

    public function setLink(?Link $link): static
    {
        $this->link = $link;

        return $this;
    }

    public function getDevice(): string
    {
        return $this->device;
    }

    public function setDevice(?string $device): static
    {
        $this->device = mb_strtolower(trim((string) $device));

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
}
