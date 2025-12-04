<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DeviceTypeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeviceTypeRepository::class)]
#[ORM\Table(name: 'device_types')]
#[ORM\Index(columns: ['display_category'], name: 'idx_device_types_category')]
#[ORM\Index(columns: ['spec_version'], name: 'idx_device_types_spec_version')]
class DeviceType
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(name: 'hex_id', length: 10)]
    private string $hexId;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'spec_version', length: 10, nullable: true)]
    private ?string $specVersion = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(name: 'display_category', length: 50, nullable: true)]
    private ?string $displayCategory = null;

    #[ORM\Column(name: 'device_class', length: 50, nullable: true)]
    private ?string $deviceClass = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $scope = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $superset = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(name: 'mandatory_server_clusters', type: Types::JSON)]
    private array $mandatoryServerClusters = [];

    #[ORM\Column(name: 'optional_server_clusters', type: Types::JSON)]
    private array $optionalServerClusters = [];

    #[ORM\Column(name: 'mandatory_client_clusters', type: Types::JSON)]
    private array $mandatoryClientClusters = [];

    #[ORM\Column(name: 'optional_client_clusters', type: Types::JSON)]
    private array $optionalClientClusters = [];

    #[ORM\Column(name: 'scoring_weights', type: Types::JSON, nullable: true)]
    private ?array $scoringWeights = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct(int $id)
    {
        $this->id = $id;
        $this->hexId = sprintf('0x%04X', $id);
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getHexId(): string
    {
        return $this->hexId;
    }

    public function setHexId(string $hexId): static
    {
        $this->hexId = $hexId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSpecVersion(): ?string
    {
        return $this->specVersion;
    }

    public function setSpecVersion(?string $specVersion): static
    {
        $this->specVersion = $specVersion;

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

    public function getDisplayCategory(): ?string
    {
        return $this->displayCategory;
    }

    public function setDisplayCategory(?string $displayCategory): static
    {
        $this->displayCategory = $displayCategory;

        return $this;
    }

    public function getDeviceClass(): ?string
    {
        return $this->deviceClass;
    }

    public function setDeviceClass(?string $deviceClass): static
    {
        $this->deviceClass = $deviceClass;

        return $this;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function setScope(?string $scope): static
    {
        $this->scope = $scope;

        return $this;
    }

    public function getSuperset(): ?string
    {
        return $this->superset;
    }

    public function setSuperset(?string $superset): static
    {
        $this->superset = $superset;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getMandatoryServerClusters(): array
    {
        return $this->mandatoryServerClusters;
    }

    public function setMandatoryServerClusters(array $clusters): static
    {
        $this->mandatoryServerClusters = $clusters;

        return $this;
    }

    public function getOptionalServerClusters(): array
    {
        return $this->optionalServerClusters;
    }

    public function setOptionalServerClusters(array $clusters): static
    {
        $this->optionalServerClusters = $clusters;

        return $this;
    }

    public function getMandatoryClientClusters(): array
    {
        return $this->mandatoryClientClusters;
    }

    public function setMandatoryClientClusters(array $clusters): static
    {
        $this->mandatoryClientClusters = $clusters;

        return $this;
    }

    public function getOptionalClientClusters(): array
    {
        return $this->optionalClientClusters;
    }

    public function setOptionalClientClusters(array $clusters): static
    {
        $this->optionalClientClusters = $clusters;

        return $this;
    }

    public function getScoringWeights(): ?array
    {
        return $this->scoringWeights;
    }

    public function setScoringWeights(?array $scoringWeights): static
    {
        $this->scoringWeights = $scoringWeights;

        return $this;
    }

    /**
     * Get scoring weights with defaults applied.
     *
     * @return array{mandatoryServerWeight: float, mandatoryClientWeight: float, optionalServerWeight: float, optionalClientWeight: float, keyClientClusters: int[], keyClientBonus: float}
     */
    public function getScoringWeightsWithDefaults(): array
    {
        $defaults = [
            'mandatoryServerWeight' => 0.40,
            'mandatoryClientWeight' => 0.20,
            'optionalServerWeight' => 0.25,
            'optionalClientWeight' => 0.15,
            'keyClientClusters' => [],
            'keyClientBonus' => 0.0,
        ];

        if (null === $this->scoringWeights) {
            return $defaults;
        }

        return array_merge($defaults, $this->scoringWeights);
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

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getTotalServerClusters(): int
    {
        return count($this->mandatoryServerClusters) + count($this->optionalServerClusters);
    }

    public function getTotalClientClusters(): int
    {
        return count($this->mandatoryClientClusters) + count($this->optionalClientClusters);
    }

    public function getTotalClusters(): int
    {
        return $this->getTotalServerClusters() + $this->getTotalClientClusters();
    }
}
