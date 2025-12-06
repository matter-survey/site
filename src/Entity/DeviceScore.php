<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DeviceScoreRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeviceScoreRepository::class)]
#[ORM\Table(name: 'device_scores')]
#[ORM\Index(columns: ['star_rating'], name: 'idx_device_scores_rating')]
#[ORM\Index(columns: ['overall_score'], name: 'idx_device_scores_score')]
#[ORM\Index(columns: ['is_compliant'], name: 'idx_device_scores_compliant')]
class DeviceScore
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Product::class, inversedBy: 'score')]
    #[ORM\JoinColumn(name: 'device_id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(name: 'overall_score', type: Types::FLOAT)]
    private float $overallScore;

    #[ORM\Column(name: 'star_rating')]
    private int $starRating;

    #[ORM\Column(name: 'is_compliant')]
    private bool $isCompliant = true;

    /** @var array<int, array<string, mixed>> */
    #[ORM\Column(name: 'scores_by_type', type: Types::JSON)]
    private array $scoresByType = [];

    #[ORM\Column(name: 'best_version', type: Types::TEXT, nullable: true)]
    private ?string $bestVersion = null;

    #[ORM\Column(name: 'computed_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $computedAt;

    public function __construct()
    {
        $this->computedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function setProduct(Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getOverallScore(): float
    {
        return $this->overallScore;
    }

    public function setOverallScore(float $overallScore): static
    {
        $this->overallScore = $overallScore;

        return $this;
    }

    public function getStarRating(): int
    {
        return $this->starRating;
    }

    public function setStarRating(int $starRating): static
    {
        $this->starRating = $starRating;

        return $this;
    }

    public function isCompliant(): bool
    {
        return $this->isCompliant;
    }

    public function setIsCompliant(bool $isCompliant): static
    {
        $this->isCompliant = $isCompliant;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getScoresByType(): array
    {
        return $this->scoresByType;
    }

    /**
     * @param array<int, array<string, mixed>> $scoresByType
     */
    public function setScoresByType(array $scoresByType): static
    {
        $this->scoresByType = $scoresByType;

        return $this;
    }

    public function getBestVersion(): ?string
    {
        return $this->bestVersion;
    }

    public function setBestVersion(?string $bestVersion): static
    {
        $this->bestVersion = $bestVersion;

        return $this;
    }

    public function getComputedAt(): \DateTimeInterface
    {
        return $this->computedAt;
    }

    public function setComputedAt(\DateTimeInterface $computedAt): static
    {
        $this->computedAt = $computedAt;

        return $this;
    }
}
