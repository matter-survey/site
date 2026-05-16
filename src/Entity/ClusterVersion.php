<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClusterVersionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Snapshot of a Matter cluster's spec at a specific Matter release.
 *
 * Composite primary key (cluster_id, matter_version) so the same cluster appears
 * once per Matter version it shipped in. ClusterRevision is the cluster's own
 * 0xFFFD-attribute counter as observed in that release's ZAP XML.
 *
 * Hand-curated fields like category/isGlobal/specVersion live on the Cluster
 * entity (the annotated "latest" view); this table carries only what upstream
 * provides at each tagged release.
 */
#[ORM\Entity(repositoryClass: ClusterVersionRepository::class)]
#[ORM\Table(name: 'cluster_versions')]
#[ORM\Index(name: 'idx_cluster_versions_version', columns: ['matter_version'])]
#[ORM\Index(name: 'idx_cluster_versions_cluster', columns: ['cluster_id'])]
class ClusterVersion
{
    #[ORM\Column(name: 'cluster_revision', nullable: true)]
    private ?int $clusterRevision = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'api_maturity', length: 20, nullable: true)]
    private ?string $apiMaturity = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $attributes = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $commands = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $features = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'cluster_id')]
        private readonly int $clusterId,
        #[ORM\Id]
        #[ORM\Column(name: 'matter_version', length: 10)]
        private readonly string $matterVersion,
    ) {
    }

    public function getClusterId(): int
    {
        return $this->clusterId;
    }

    public function getMatterVersion(): string
    {
        return $this->matterVersion;
    }

    public function getClusterRevision(): ?int
    {
        return $this->clusterRevision;
    }

    public function setClusterRevision(?int $clusterRevision): static
    {
        $this->clusterRevision = $clusterRevision;

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

    public function getApiMaturity(): ?string
    {
        return $this->apiMaturity;
    }

    public function setApiMaturity(?string $apiMaturity): static
    {
        $this->apiMaturity = $apiMaturity;

        return $this;
    }

    public function getAttributes(): ?array
    {
        return $this->attributes;
    }

    public function setAttributes(?array $attributes): static
    {
        $this->attributes = $attributes;

        return $this;
    }

    public function getCommands(): ?array
    {
        return $this->commands;
    }

    public function setCommands(?array $commands): static
    {
        $this->commands = $commands;

        return $this;
    }

    public function getFeatures(): ?array
    {
        return $this->features;
    }

    public function setFeatures(?array $features): static
    {
        $this->features = $features;

        return $this;
    }
}
