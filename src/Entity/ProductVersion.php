<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductVersionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductVersionRepository::class)]
#[ORM\Table(name: 'product_versions')]
#[ORM\UniqueConstraint(
    name: 'unique_product_version',
    columns: ['device_id', 'hardware_version', 'software_version']
)]
#[ORM\Index(columns: ['device_id'], name: 'idx_product_versions_product')]
class ProductVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'versions')]
    #[ORM\JoinColumn(name: 'device_id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(name: 'hardware_version', length: 50, nullable: true)]
    private ?string $hardwareVersion = null;

    #[ORM\Column(name: 'software_version', length: 50, nullable: true)]
    private ?string $softwareVersion = null;

    #[ORM\Column(name: 'first_seen', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $firstSeen = null;

    #[ORM\Column(name: 'last_seen', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastSeen = null;

    #[ORM\Column]
    private int $count = 1;

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

    public function getCount(): int
    {
        return $this->count;
    }

    public function setCount(int $count): static
    {
        $this->count = $count;

        return $this;
    }

    public function incrementCount(): static
    {
        ++$this->count;

        return $this;
    }
}
