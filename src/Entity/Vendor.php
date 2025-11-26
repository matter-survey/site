<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VendorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VendorRepository::class)]
#[ORM\Table(name: 'vendors')]
#[ORM\Index(columns: ['spec_id'], name: 'idx_vendors_spec_id')]
class Vendor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $slug;

    #[ORM\Column(name: 'spec_id', nullable: true, unique: true)]
    private ?int $specId = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(name: 'device_count')]
    private int $deviceCount = 0;

    #[ORM\Column(name: 'company_legal_name', length: 512, nullable: true)]
    private ?string $companyLegalName = null;

    #[ORM\Column(name: 'vendor_landing_page_url', length: 512, nullable: true)]
    private ?string $vendorLandingPageURL = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getSpecId(): ?int
    {
        return $this->specId;
    }

    public function setSpecId(?int $specId): static
    {
        $this->specId = $specId;

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

    public function getDeviceCount(): int
    {
        return $this->deviceCount;
    }

    public function setDeviceCount(int $deviceCount): static
    {
        $this->deviceCount = $deviceCount;

        return $this;
    }

    public function incrementDeviceCount(): static
    {
        $this->deviceCount++;

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

    public function getCompanyLegalName(): ?string
    {
        return $this->companyLegalName;
    }

    public function setCompanyLegalName(?string $companyLegalName): static
    {
        $this->companyLegalName = $companyLegalName;

        return $this;
    }

    public function getVendorLandingPageURL(): ?string
    {
        return $this->vendorLandingPageURL;
    }

    public function setVendorLandingPageURL(?string $vendorLandingPageURL): static
    {
        $this->vendorLandingPageURL = $vendorLandingPageURL;

        return $this;
    }

    /**
     * Generate a URL-friendly slug from the vendor name.
     */
    public static function generateSlug(string $name, ?int $specId = null): string
    {
        if (empty($name) && $specId !== null) {
            return 'vendor-' . $specId;
        }

        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s_]+/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug ?: 'vendor-' . ($specId ?? 'unknown');
    }
}
