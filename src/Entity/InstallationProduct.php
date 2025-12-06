<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InstallationProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstallationProductRepository::class)]
#[ORM\Table(name: 'installation_products')]
#[ORM\UniqueConstraint(
    name: 'unique_installation_product',
    columns: ['installation_id', 'product_id']
)]
#[ORM\Index(columns: ['installation_id'], name: 'idx_installation_products_installation')]
#[ORM\Index(columns: ['product_id'], name: 'idx_installation_products_product')]
class InstallationProduct
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'installation_id', type: Types::TEXT)]
    private string $installationId;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(name: 'first_seen', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $firstSeen = null;

    #[ORM\Column(name: 'last_seen', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastSeen = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInstallationId(): string
    {
        return $this->installationId;
    }

    public function setInstallationId(string $installationId): static
    {
        $this->installationId = $installationId;

        return $this;
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
}
