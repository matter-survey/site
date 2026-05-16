<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VendorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VendorRepository::class)]
#[ORM\Table(name: 'vendors')]
#[ORM\Index(name: 'idx_vendors_spec_id', columns: ['spec_id'])]
class Vendor
{
    /**
     * Vendor IDs reserved by the Matter spec for development & test devices,
     * plus the well-known "Demo Vendor" (VID 1234) used in sample telemetry.
     * These are never real products and should not appear in public aggregates.
     */
    public const array TEST_VENDOR_IDS = [
        1234,   // "Demo Vendor" — common Home Assistant demo/sample value
        65521,  // 0xFFF1 — Test Vendor #1
        65522,  // 0xFFF2 — Test Vendor #2
        65523,  // 0xFFF3 — Test Vendor #3
        65524,  // 0xFFF4 — Test Vendor #4
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $slug;

    #[ORM\Column(name: 'spec_id', unique: true, nullable: true)]
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
        ++$this->deviceCount;

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
        if (('' === $name || '0' === $name) && null !== $specId) {
            return 'vendor-'.$specId;
        }

        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s_]+/', '-', (string) $slug);
        $slug = preg_replace('/-+/', '-', (string) $slug);
        $slug = trim((string) $slug, '-');

        return $slug ?: 'vendor-'.($specId ?? 'unknown');
    }

    /**
     * Whether the given specId is a known dev/test VID that should be hidden
     * from public aggregates.
     */
    public static function isTestVendorId(?int $specId): bool
    {
        return null !== $specId && \in_array($specId, self::TEST_VENDOR_IDS, true);
    }

    /**
     * Canonical vendor slug — always suffixed with specId so that renames or
     * different vendors slugifying to the same base remain unique.
     */
    public static function canonicalSlug(string $name, int $specId): string
    {
        $base = self::generateSlug($name, $specId);

        // Avoid 'vendor-42-42' when name slugifies to the specId fallback.
        if ('vendor-'.$specId === $base) {
            return $base;
        }

        return $base.'-'.$specId;
    }
}
