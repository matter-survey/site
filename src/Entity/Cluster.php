<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClusterRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClusterRepository::class)]
#[ORM\Table(name: 'clusters')]
#[ORM\Index(columns: ['hex_id'], name: 'idx_clusters_hex_id')]
#[ORM\Index(columns: ['category'], name: 'idx_clusters_category')]
#[ORM\Index(columns: ['spec_version'], name: 'idx_clusters_spec_version')]
class Cluster
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(name: 'hex_id', length: 10, unique: true)]
    private string $hexId;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'spec_version', length: 10, nullable: true)]
    private ?string $specVersion = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(name: 'is_global', type: Types::BOOLEAN)]
    private bool $isGlobal = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct(int $id)
    {
        $this->id = $id;
        $this->hexId = \sprintf('0x%04X', $id);
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

    public function isGlobal(): bool
    {
        return $this->isGlobal;
    }

    public function setIsGlobal(bool $isGlobal): static
    {
        $this->isGlobal = $isGlobal;

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

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
