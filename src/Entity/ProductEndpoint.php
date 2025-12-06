<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductEndpointRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductEndpointRepository::class)]
#[ORM\Table(name: 'product_endpoints')]
#[ORM\UniqueConstraint(
    name: 'unique_endpoint_version',
    columns: ['device_id', 'endpoint_id', 'hardware_version', 'software_version']
)]
#[ORM\Index(columns: ['device_id'], name: 'idx_product_endpoints_product')]
#[ORM\Index(columns: ['device_id', 'hardware_version', 'software_version'], name: 'idx_product_endpoints_version')]
class ProductEndpoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'endpoints')]
    #[ORM\JoinColumn(name: 'device_id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(name: 'endpoint_id')]
    private int $endpointId;

    #[ORM\Column(name: 'hardware_version', length: 50, nullable: true)]
    private ?string $hardwareVersion = null;

    #[ORM\Column(name: 'software_version', length: 50, nullable: true)]
    private ?string $softwareVersion = null;

    /** @var array<int> */
    #[ORM\Column(name: 'device_types', type: Types::JSON)]
    private array $deviceTypes = [];

    /** @var array<int> */
    #[ORM\Column(name: 'server_clusters', type: Types::JSON)]
    private array $serverClusters = [];

    /** @var array<int> */
    #[ORM\Column(name: 'client_clusters', type: Types::JSON)]
    private array $clientClusters = [];

    /** @var array<int, array<string, mixed>>|null */
    #[ORM\Column(name: 'server_cluster_details', type: Types::JSON, nullable: true)]
    private ?array $serverClusterDetails = null;

    /** @var array<int, array<string, mixed>>|null */
    #[ORM\Column(name: 'client_cluster_details', type: Types::JSON, nullable: true)]
    private ?array $clientClusterDetails = null;

    #[ORM\Column(name: 'schema_version')]
    private int $schemaVersion = 2;

    #[ORM\Column(name: 'first_seen', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $firstSeen = null;

    #[ORM\Column(name: 'last_seen', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastSeen = null;

    #[ORM\Column(name: 'submission_count')]
    private int $submissionCount = 1;

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

    public function getEndpointId(): int
    {
        return $this->endpointId;
    }

    public function setEndpointId(int $endpointId): static
    {
        $this->endpointId = $endpointId;

        return $this;
    }

    public function getHardwareVersion(): ?string
    {
        return $this->hardwareVersion;
    }

    public function setHardwareVersion(?string $hardwareVersion): static
    {
        $this->hardwareVersion = $hardwareVersion;

        return $this;
    }

    public function getSoftwareVersion(): ?string
    {
        return $this->softwareVersion;
    }

    public function setSoftwareVersion(?string $softwareVersion): static
    {
        $this->softwareVersion = $softwareVersion;

        return $this;
    }

    /**
     * @return array<int>
     */
    public function getDeviceTypes(): array
    {
        return $this->deviceTypes;
    }

    /**
     * @param array<int> $deviceTypes
     */
    public function setDeviceTypes(array $deviceTypes): static
    {
        $this->deviceTypes = $deviceTypes;

        return $this;
    }

    /**
     * @return array<int>
     */
    public function getServerClusters(): array
    {
        return $this->serverClusters;
    }

    /**
     * @param array<int> $serverClusters
     */
    public function setServerClusters(array $serverClusters): static
    {
        $this->serverClusters = $serverClusters;

        return $this;
    }

    /**
     * @return array<int>
     */
    public function getClientClusters(): array
    {
        return $this->clientClusters;
    }

    /**
     * @param array<int> $clientClusters
     */
    public function setClientClusters(array $clientClusters): static
    {
        $this->clientClusters = $clientClusters;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function getServerClusterDetails(): ?array
    {
        return $this->serverClusterDetails;
    }

    /**
     * @param array<int, array<string, mixed>>|null $serverClusterDetails
     */
    public function setServerClusterDetails(?array $serverClusterDetails): static
    {
        $this->serverClusterDetails = $serverClusterDetails;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function getClientClusterDetails(): ?array
    {
        return $this->clientClusterDetails;
    }

    /**
     * @param array<int, array<string, mixed>>|null $clientClusterDetails
     */
    public function setClientClusterDetails(?array $clientClusterDetails): static
    {
        $this->clientClusterDetails = $clientClusterDetails;

        return $this;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function setSchemaVersion(int $schemaVersion): static
    {
        $this->schemaVersion = $schemaVersion;

        return $this;
    }

    public function getFirstSeen(): ?\DateTimeInterface
    {
        return $this->firstSeen;
    }

    public function setFirstSeen(?\DateTimeInterface $firstSeen): static
    {
        $this->firstSeen = $firstSeen;

        return $this;
    }

    public function getLastSeen(): ?\DateTimeInterface
    {
        return $this->lastSeen;
    }

    public function setLastSeen(?\DateTimeInterface $lastSeen): static
    {
        $this->lastSeen = $lastSeen;

        return $this;
    }

    public function getSubmissionCount(): int
    {
        return $this->submissionCount;
    }

    public function setSubmissionCount(int $submissionCount): static
    {
        $this->submissionCount = $submissionCount;

        return $this;
    }

    public function incrementSubmissionCount(): static
    {
        ++$this->submissionCount;

        return $this;
    }
}
