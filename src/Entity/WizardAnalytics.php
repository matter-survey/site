<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WizardAnalyticsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WizardAnalyticsRepository::class)]
#[ORM\Table(name: 'wizard_analytics')]
#[ORM\Index(columns: ['session_id'], name: 'idx_wizard_session')]
#[ORM\Index(columns: ['category'], name: 'idx_wizard_category')]
#[ORM\Index(columns: ['created_at'], name: 'idx_wizard_created')]
class WizardAnalytics
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(name: 'session_id', length: 64)]
    private string $sessionId;

    #[ORM\Column]
    private int $step;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $category = null;

    /**
     * @var array<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $connectivity = null;

    #[ORM\Column(name: 'min_rating', nullable: true)]
    private ?int $minRating = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $binding = null;

    #[ORM\Column(name: 'owned_device_count', nullable: true)]
    private ?int $ownedDeviceCount = null;

    #[ORM\Column]
    private bool $completed = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): static
    {
        $this->sessionId = $sessionId;

        return $this;
    }

    public function getStep(): int
    {
        return $this->step;
    }

    public function setStep(int $step): static
    {
        $this->step = $step;

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = $category;

        return $this;
    }

    /**
     * @return array<string>|null
     */
    public function getConnectivity(): ?array
    {
        return $this->connectivity;
    }

    /**
     * @param array<string>|null $connectivity
     */
    public function setConnectivity(?array $connectivity): static
    {
        $this->connectivity = $connectivity;

        return $this;
    }

    public function getMinRating(): ?int
    {
        return $this->minRating;
    }

    public function setMinRating(?int $minRating): static
    {
        $this->minRating = $minRating;

        return $this;
    }

    public function getBinding(): ?string
    {
        return $this->binding;
    }

    public function setBinding(?string $binding): static
    {
        $this->binding = $binding;

        return $this;
    }

    public function getOwnedDeviceCount(): ?int
    {
        return $this->ownedDeviceCount;
    }

    public function setOwnedDeviceCount(?int $ownedDeviceCount): static
    {
        $this->ownedDeviceCount = $ownedDeviceCount;

        return $this;
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function setCompleted(bool $completed): static
    {
        $this->completed = $completed;

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
