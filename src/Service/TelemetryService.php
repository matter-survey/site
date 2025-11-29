<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\DeviceRepository;
use App\Repository\VendorRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class TelemetryService
{
    /**
     * Bridged Node device type ID (0x0013).
     * Endpoints with this device type represent devices bridged from other protocols
     * (Z-Wave, Zigbee, etc.) and are user-specific, so we don't record them.
     */
    private const BRIDGED_NODE_DEVICE_TYPE_ID = 19;

    /**
     * Network Diagnostics cluster IDs that indicate connectivity type.
     */
    private const CONNECTIVITY_CLUSTERS = [
        53 => 'thread',   // Thread Network Diagnostics
        54 => 'wifi',     // WiFi Network Diagnostics
        55 => 'ethernet', // Ethernet Network Diagnostics
    ];

    private Connection $db;

    public function __construct(
        DatabaseService $databaseService,
        private DeviceRepository $deviceRepo,
        private VendorRepository $vendorRepo,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
        $this->db = $databaseService->getConnection();
    }

    /**
     * Process a telemetry submission.
     */
    public function processSubmission(array $payload, ?string $ipHash = null): array
    {
        $validation = $this->validatePayload($payload);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }

        $installationId = $payload['installation_id'];
        $devices = $payload['devices'];

        try {
            $this->db->beginTransaction();

            $this->recordInstallation($installationId);
            $this->logSubmission($installationId, count($devices), $ipHash);

            $processedCount = 0;
            foreach ($devices as $device) {
                $productId = $this->processDevice($device);
                if (null !== $productId) {
                    $this->recordInstallationProduct($installationId, $productId);
                    ++$processedCount;
                }
            }

            // Flush ORM changes (vendors)
            $this->em->flush();

            $this->db->commit();

            $this->logger->info('Telemetry submission processed', [
                'installation_id' => $installationId,
                'devices_processed' => $processedCount,
            ]);

            return [
                'success' => true,
                'message' => "Processed $processedCount devices",
                'devices_processed' => $processedCount,
            ];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Telemetry submission failed', [
                'installation_id' => $installationId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return ['success' => false, 'error' => 'Database error: '.$e->getMessage()];
        }
    }

    private function validatePayload(array $payload): array
    {
        if (empty($payload['installation_id'])) {
            return ['valid' => false, 'error' => 'Missing installation_id'];
        }

        if (!isset($payload['devices']) || !is_array($payload['devices'])) {
            return ['valid' => false, 'error' => 'Missing or invalid devices array'];
        }

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $payload['installation_id'])) {
            return ['valid' => false, 'error' => 'Invalid installation_id format'];
        }

        return ['valid' => true];
    }

    private function recordInstallation(string $installationId): void
    {
        $this->db->executeStatement('
            INSERT INTO installations (installation_id, last_seen, submission_count)
            VALUES (:id, CURRENT_TIMESTAMP, 1)
            ON CONFLICT(installation_id) DO UPDATE SET
                last_seen = CURRENT_TIMESTAMP,
                submission_count = installations.submission_count + 1
        ', ['id' => $installationId]);
    }

    private function logSubmission(string $installationId, int $deviceCount, ?string $ipHash): void
    {
        $this->db->executeStatement('
            INSERT INTO submissions (installation_id, device_count, ip_hash)
            VALUES (:installation_id, :device_count, :ip_hash)
        ', [
            'installation_id' => $installationId,
            'device_count' => $deviceCount,
            'ip_hash' => $ipHash,
        ]);
    }

    /**
     * Process a single device from the telemetry payload.
     *
     * @return int|null The product ID if successfully processed, null otherwise
     */
    private function processDevice(array $device): ?int
    {
        if (empty($device['vendor_id']) && empty($device['product_id'])) {
            return null;
        }

        $vendorId = $device['vendor_id'] ?? null;
        $vendorName = $this->sanitizeString($device['vendor_name'] ?? null);
        $vendorFk = null;

        // Find or create vendor if we have a vendor_id
        if (null !== $vendorId) {
            $vendor = $this->vendorRepo->findOrCreateBySpecId((int) $vendorId, $vendorName);
            $vendorFk = $vendor->getId();

            // If this is a new vendor, we need to flush to get the ID
            if (null === $vendorFk) {
                $this->em->flush();
                $vendorFk = $vendor->getId();
            }
        }

        // Extract connectivity types from endpoint clusters
        $connectivityTypes = $this->extractConnectivityTypes($device['endpoints'] ?? []);

        $isNewDevice = false;
        $deviceId = $this->deviceRepo->upsertDevice([
            'vendor_id' => $vendorId,
            'vendor_name' => $vendorName,
            'vendor_fk' => $vendorFk,
            'product_id' => $device['product_id'] ?? null,
            'product_name' => $this->sanitizeString($device['product_name'] ?? null),
            'connectivity_types' => $connectivityTypes,
        ], $isNewDevice);

        // Update vendor device count if this is a new device
        if ($isNewDevice && null !== $vendorFk) {
            $vendor = $this->vendorRepo->find($vendorFk);
            if ($vendor) {
                $vendor->incrementDeviceCount();
            }
        }

        $hardwareVersion = $this->sanitizeString($device['hardware_version'] ?? null);
        $softwareVersion = $this->sanitizeString($device['software_version'] ?? null);

        $this->deviceRepo->upsertVersion($deviceId, $hardwareVersion, $softwareVersion);

        foreach ($device['endpoints'] ?? [] as $endpoint) {
            // Skip bridged node endpoints - they represent user-specific bridged devices
            if ($this->isBridgedNodeEndpoint($endpoint)) {
                continue;
            }

            $this->deviceRepo->upsertEndpoint(
                $deviceId,
                [
                    'endpoint_id' => $endpoint['endpoint_id'] ?? 0,
                    'device_types' => $endpoint['device_types'] ?? [],
                    'server_clusters' => $endpoint['server_clusters'] ?? [],
                    'client_clusters' => $endpoint['client_clusters'] ?? [],
                ],
                $hardwareVersion,
                $softwareVersion
            );
        }

        return $deviceId;
    }

    /**
     * Record that a product belongs to an installation (for pairing analytics).
     */
    private function recordInstallationProduct(string $installationId, int $productId): void
    {
        $this->db->executeStatement('
            INSERT INTO installation_products (installation_id, product_id, first_seen, last_seen)
            VALUES (:installation_id, :product_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT(installation_id, product_id) DO UPDATE SET
                last_seen = CURRENT_TIMESTAMP
        ', [
            'installation_id' => $installationId,
            'product_id' => $productId,
        ]);
    }

    /**
     * Extract connectivity types from endpoint server clusters.
     *
     * @param array<array{server_clusters?: array<int>}> $endpoints
     *
     * @return array<string>
     */
    private function extractConnectivityTypes(array $endpoints): array
    {
        $types = [];

        foreach ($endpoints as $endpoint) {
            foreach ($endpoint['server_clusters'] ?? [] as $clusterId) {
                if (isset(self::CONNECTIVITY_CLUSTERS[$clusterId])) {
                    $types[] = self::CONNECTIVITY_CLUSTERS[$clusterId];
                }
            }
        }

        $unique = array_unique($types);
        sort($unique);

        return $unique;
    }

    private function sanitizeString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);
        if (\strlen($value) > 255) {
            $value = substr($value, 0, 255);
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);

        return $value ?: null;
    }

    /**
     * Check if an endpoint is a Bridged Node.
     * Device types can be either an array of IDs or an array of objects with 'id' field.
     */
    private function isBridgedNodeEndpoint(array $endpoint): bool
    {
        $deviceTypes = $endpoint['device_types'] ?? [];

        foreach ($deviceTypes as $deviceType) {
            // Handle both formats: plain ID or object with 'id' field
            $typeId = \is_array($deviceType) ? ($deviceType['id'] ?? null) : $deviceType;

            if (self::BRIDGED_NODE_DEVICE_TYPE_ID === (int) $typeId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get submission statistics.
     */
    public function getStats(): array
    {
        return [
            'total_devices' => (int) $this->db->executeQuery('SELECT COUNT(*) FROM products')->fetchOne(),
            'total_vendors' => (int) $this->db->executeQuery('SELECT COUNT(*) FROM vendors')->fetchOne(),
            'total_installations' => (int) $this->db->executeQuery('SELECT COUNT(*) FROM installations')->fetchOne(),
            'total_submissions' => (int) $this->db->executeQuery('SELECT COUNT(*) FROM submissions')->fetchOne(),
            'bindable_devices' => (int) $this->db->executeQuery('SELECT COUNT(*) FROM product_summary WHERE supports_binding = 1')->fetchOne(),
        ];
    }
}
