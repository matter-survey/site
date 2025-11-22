<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
#[ORM\UniqueConstraint(name: 'unique_vendor_product', columns: ['vendor_id', 'product_id'])]
#[ORM\Index(columns: ['vendor_id'], name: 'idx_products_vendor')]
#[ORM\Index(columns: ['product_id'], name: 'idx_products_product')]
#[ORM\Index(columns: ['vendor_fk'], name: 'idx_products_vendor_fk')]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'vendor_id')]
    private int $vendorId;

    #[ORM\Column(name: 'vendor_name', type: Types::TEXT, nullable: true)]
    private ?string $vendorName = null;

    #[ORM\Column(name: 'product_id')]
    private int $productId;

    #[ORM\Column(name: 'product_name', type: Types::TEXT, nullable: true)]
    private ?string $productName = null;

    #[ORM\Column(name: 'first_seen', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $firstSeen;

    #[ORM\Column(name: 'last_seen', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $lastSeen;

    #[ORM\Column(name: 'submission_count')]
    private int $submissionCount = 1;

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_fk', referencedColumnName: 'id', nullable: true)]
    private ?Vendor $vendor = null;

    public function __construct()
    {
        $this->firstSeen = new \DateTime();
        $this->lastSeen = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVendorId(): int
    {
        return $this->vendorId;
    }

    public function setVendorId(int $vendorId): static
    {
        $this->vendorId = $vendorId;

        return $this;
    }

    public function getVendorName(): ?string
    {
        return $this->vendorName;
    }

    public function setVendorName(?string $vendorName): static
    {
        $this->vendorName = $vendorName;

        return $this;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function setProductId(int $productId): static
    {
        $this->productId = $productId;

        return $this;
    }

    public function getProductName(): ?string
    {
        return $this->productName;
    }

    public function setProductName(?string $productName): static
    {
        $this->productName = $productName;

        return $this;
    }

    public function getFirstSeen(): \DateTimeInterface
    {
        return $this->firstSeen;
    }

    public function setFirstSeen(\DateTimeInterface $firstSeen): static
    {
        $this->firstSeen = $firstSeen;

        return $this;
    }

    public function getLastSeen(): \DateTimeInterface
    {
        return $this->lastSeen;
    }

    public function setLastSeen(\DateTimeInterface $lastSeen): static
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
        $this->submissionCount++;
        $this->lastSeen = new \DateTime();

        return $this;
    }

    public function getVendor(): ?Vendor
    {
        return $this->vendor;
    }

    public function setVendor(?Vendor $vendor): static
    {
        $this->vendor = $vendor;

        return $this;
    }
}
