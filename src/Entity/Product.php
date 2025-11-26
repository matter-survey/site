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

    #[ORM\Column(name: 'device_type_id', nullable: true)]
    private ?int $deviceTypeId = null;

    #[ORM\Column(name: 'part_number', length: 255, nullable: true)]
    private ?string $partNumber = null;

    #[ORM\Column(name: 'product_url', length: 512, nullable: true)]
    private ?string $productUrl = null;

    #[ORM\Column(name: 'support_url', length: 512, nullable: true)]
    private ?string $supportUrl = null;

    #[ORM\Column(name: 'user_manual_url', length: 512, nullable: true)]
    private ?string $userManualUrl = null;

    #[ORM\Column(name: 'discovery_capabilities_bitmask', nullable: true)]
    private ?int $discoveryCapabilitiesBitmask = null;

    #[ORM\Column(name: 'commissioning_custom_flow', nullable: true)]
    private ?int $commissioningCustomFlow = null;

    #[ORM\Column(name: 'commissioning_custom_flow_url', length: 512, nullable: true)]
    private ?string $commissioningCustomFlowUrl = null;

    #[ORM\Column(name: 'commissioning_initial_steps_hint', nullable: true)]
    private ?int $commissioningInitialStepsHint = null;

    #[ORM\Column(name: 'commissioning_initial_steps_instruction', type: Types::TEXT, nullable: true)]
    private ?string $commissioningInitialStepsInstruction = null;

    #[ORM\Column(name: 'maintenance_url', length: 512, nullable: true)]
    private ?string $maintenanceUrl = null;

    #[ORM\Column(name: 'factory_reset_steps_hint', nullable: true)]
    private ?int $factoryResetStepsHint = null;

    #[ORM\Column(name: 'factory_reset_steps_instruction', type: Types::TEXT, nullable: true)]
    private ?string $factoryResetStepsInstruction = null;

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

    public function getDeviceTypeId(): ?int
    {
        return $this->deviceTypeId;
    }

    public function setDeviceTypeId(?int $deviceTypeId): static
    {
        $this->deviceTypeId = $deviceTypeId;

        return $this;
    }

    public function getPartNumber(): ?string
    {
        return $this->partNumber;
    }

    public function setPartNumber(?string $partNumber): static
    {
        $this->partNumber = $partNumber;

        return $this;
    }

    public function getProductUrl(): ?string
    {
        return $this->productUrl;
    }

    public function setProductUrl(?string $productUrl): static
    {
        $this->productUrl = $productUrl;

        return $this;
    }

    public function getSupportUrl(): ?string
    {
        return $this->supportUrl;
    }

    public function setSupportUrl(?string $supportUrl): static
    {
        $this->supportUrl = $supportUrl;

        return $this;
    }

    public function getUserManualUrl(): ?string
    {
        return $this->userManualUrl;
    }

    public function setUserManualUrl(?string $userManualUrl): static
    {
        $this->userManualUrl = $userManualUrl;

        return $this;
    }

    public function getDiscoveryCapabilitiesBitmask(): ?int
    {
        return $this->discoveryCapabilitiesBitmask;
    }

    public function setDiscoveryCapabilitiesBitmask(?int $discoveryCapabilitiesBitmask): static
    {
        $this->discoveryCapabilitiesBitmask = $discoveryCapabilitiesBitmask;

        return $this;
    }

    public function getCommissioningCustomFlow(): ?int
    {
        return $this->commissioningCustomFlow;
    }

    public function setCommissioningCustomFlow(?int $commissioningCustomFlow): static
    {
        $this->commissioningCustomFlow = $commissioningCustomFlow;

        return $this;
    }

    public function getCommissioningCustomFlowUrl(): ?string
    {
        return $this->commissioningCustomFlowUrl;
    }

    public function setCommissioningCustomFlowUrl(?string $commissioningCustomFlowUrl): static
    {
        $this->commissioningCustomFlowUrl = $commissioningCustomFlowUrl;

        return $this;
    }

    public function getCommissioningInitialStepsHint(): ?int
    {
        return $this->commissioningInitialStepsHint;
    }

    public function setCommissioningInitialStepsHint(?int $commissioningInitialStepsHint): static
    {
        $this->commissioningInitialStepsHint = $commissioningInitialStepsHint;

        return $this;
    }

    public function getCommissioningInitialStepsInstruction(): ?string
    {
        return $this->commissioningInitialStepsInstruction;
    }

    public function setCommissioningInitialStepsInstruction(?string $commissioningInitialStepsInstruction): static
    {
        $this->commissioningInitialStepsInstruction = $commissioningInitialStepsInstruction;

        return $this;
    }

    public function getMaintenanceUrl(): ?string
    {
        return $this->maintenanceUrl;
    }

    public function setMaintenanceUrl(?string $maintenanceUrl): static
    {
        $this->maintenanceUrl = $maintenanceUrl;

        return $this;
    }

    public function getFactoryResetStepsHint(): ?int
    {
        return $this->factoryResetStepsHint;
    }

    public function setFactoryResetStepsHint(?int $factoryResetStepsHint): static
    {
        $this->factoryResetStepsHint = $factoryResetStepsHint;

        return $this;
    }

    public function getFactoryResetStepsInstruction(): ?string
    {
        return $this->factoryResetStepsInstruction;
    }

    public function setFactoryResetStepsInstruction(?string $factoryResetStepsInstruction): static
    {
        $this->factoryResetStepsInstruction = $factoryResetStepsInstruction;

        return $this;
    }
}
