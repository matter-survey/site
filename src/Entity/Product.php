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
#[ORM\Index(columns: ['slug'], name: 'idx_products_slug')]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $slug = null;

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

    #[ORM\Column(name: 'commissioning_secondary_steps_hint', nullable: true)]
    private ?int $commissioningSecondaryStepsHint = null;

    #[ORM\Column(name: 'commissioning_secondary_steps_instruction', type: Types::TEXT, nullable: true)]
    private ?string $commissioningSecondaryStepsInstruction = null;

    #[ORM\Column(name: 'commissioning_fallback_url', length: 512, nullable: true)]
    private ?string $commissioningFallbackUrl = null;

    #[ORM\Column(name: 'icd_user_active_mode_trigger_hint', nullable: true)]
    private ?int $icdUserActiveModeTriggerHint = null;

    #[ORM\Column(name: 'icd_user_active_mode_trigger_instruction', type: Types::TEXT, nullable: true)]
    private ?string $icdUserActiveModeTriggerInstruction = null;

    #[ORM\Column(name: 'lsf_url', length: 512, nullable: true)]
    private ?string $lsfUrl = null;

    #[ORM\Column(name: 'lsf_revision', nullable: true)]
    private ?int $lsfRevision = null;

    /**
     * Certified software versions from the DCL.
     *
     * @var array<int>|null
     */
    #[ORM\Column(name: 'certified_software_versions', type: Types::JSON, nullable: true)]
    private ?array $certifiedSoftwareVersions = null;

    /**
     * Network connectivity types derived from telemetry (thread, wifi, ethernet).
     *
     * @var array<string>|null
     */
    #[ORM\Column(name: 'connectivity_types', type: Types::JSON, nullable: true)]
    private ?array $connectivityTypes = null;

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
        ++$this->submissionCount;
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

    public function getCommissioningSecondaryStepsHint(): ?int
    {
        return $this->commissioningSecondaryStepsHint;
    }

    public function setCommissioningSecondaryStepsHint(?int $commissioningSecondaryStepsHint): static
    {
        $this->commissioningSecondaryStepsHint = $commissioningSecondaryStepsHint;

        return $this;
    }

    public function getCommissioningSecondaryStepsInstruction(): ?string
    {
        return $this->commissioningSecondaryStepsInstruction;
    }

    public function setCommissioningSecondaryStepsInstruction(?string $commissioningSecondaryStepsInstruction): static
    {
        $this->commissioningSecondaryStepsInstruction = $commissioningSecondaryStepsInstruction;

        return $this;
    }

    public function getCommissioningFallbackUrl(): ?string
    {
        return $this->commissioningFallbackUrl;
    }

    public function setCommissioningFallbackUrl(?string $commissioningFallbackUrl): static
    {
        $this->commissioningFallbackUrl = $commissioningFallbackUrl;

        return $this;
    }

    public function getIcdUserActiveModeTriggerHint(): ?int
    {
        return $this->icdUserActiveModeTriggerHint;
    }

    public function setIcdUserActiveModeTriggerHint(?int $icdUserActiveModeTriggerHint): static
    {
        $this->icdUserActiveModeTriggerHint = $icdUserActiveModeTriggerHint;

        return $this;
    }

    public function getIcdUserActiveModeTriggerInstruction(): ?string
    {
        return $this->icdUserActiveModeTriggerInstruction;
    }

    public function setIcdUserActiveModeTriggerInstruction(?string $icdUserActiveModeTriggerInstruction): static
    {
        $this->icdUserActiveModeTriggerInstruction = $icdUserActiveModeTriggerInstruction;

        return $this;
    }

    public function getLsfUrl(): ?string
    {
        return $this->lsfUrl;
    }

    public function setLsfUrl(?string $lsfUrl): static
    {
        $this->lsfUrl = $lsfUrl;

        return $this;
    }

    public function getLsfRevision(): ?int
    {
        return $this->lsfRevision;
    }

    public function setLsfRevision(?int $lsfRevision): static
    {
        $this->lsfRevision = $lsfRevision;

        return $this;
    }

    /**
     * @return array<int>|null
     */
    public function getCertifiedSoftwareVersions(): ?array
    {
        return $this->certifiedSoftwareVersions;
    }

    /**
     * @param array<int>|null $certifiedSoftwareVersions
     */
    public function setCertifiedSoftwareVersions(?array $certifiedSoftwareVersions): static
    {
        $this->certifiedSoftwareVersions = $certifiedSoftwareVersions;

        return $this;
    }

    /**
     * @return array<string>|null
     */
    public function getConnectivityTypes(): ?array
    {
        return $this->connectivityTypes;
    }

    /**
     * @param array<string>|null $connectivityTypes
     */
    public function setConnectivityTypes(?array $connectivityTypes): static
    {
        $this->connectivityTypes = $connectivityTypes;

        return $this;
    }

    /**
     * Merge new connectivity types with existing ones.
     *
     * @param array<string> $newTypes
     */
    public function mergeConnectivityTypes(array $newTypes): static
    {
        $existing = $this->connectivityTypes ?? [];
        $merged = array_unique(array_merge($existing, $newTypes));
        sort($merged);
        $this->connectivityTypes = $merged ?: null;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    /**
     * Generate a URL-friendly slug from the product name and IDs.
     * Format: product-name-vendorid-productid (e.g., "eve-motion-4874-17").
     */
    public static function generateSlug(?string $productName, int $vendorId, int $productId): string
    {
        $slug = '';

        if (!empty($productName)) {
            $slug = strtolower($productName);
            $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
            $slug = preg_replace('/[\s_]+/', '-', $slug);
            $slug = preg_replace('/-+/', '-', $slug);
            $slug = trim($slug, '-');
        }

        // Always append vendor_id and product_id for uniqueness
        if ('' !== $slug) {
            return $slug.'-'.$vendorId.'-'.$productId;
        }

        return 'product-'.$vendorId.'-'.$productId;
    }
}
