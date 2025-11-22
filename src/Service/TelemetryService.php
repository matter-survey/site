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
                if ($this->processDevice($device)) {
                    $processedCount++;
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
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
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

    private function processDevice(array $device): bool
    {
        if (empty($device['vendor_id']) && empty($device['product_id'])) {
            return false;
        }

        $vendorId = $device['vendor_id'] ?? null;
        $vendorName = $this->sanitizeString($device['vendor_name'] ?? null);
        $vendorFk = null;

        // Find or create vendor if we have a vendor_id
        if ($vendorId !== null) {
            $vendor = $this->vendorRepo->findOrCreateBySpecId((int) $vendorId, $vendorName);
            $vendorFk = $vendor->getId();

            // If this is a new vendor, we need to flush to get the ID
            if ($vendorFk === null) {
                $this->em->flush();
                $vendorFk = $vendor->getId();
            }
        }

        $isNewDevice = false;
        $deviceId = $this->deviceRepo->upsertDevice([
            'vendor_id' => $vendorId,
            'vendor_name' => $vendorName,
            'vendor_fk' => $vendorFk,
            'product_id' => $device['product_id'] ?? null,
            'product_name' => $this->sanitizeString($device['product_name'] ?? null),
        ], $isNewDevice);

        // Update vendor device count if this is a new device
        if ($isNewDevice && $vendorFk !== null) {
            $vendor = $this->vendorRepo->find($vendorFk);
            if ($vendor) {
                $vendor->incrementDeviceCount();
            }
        }

        $hardwareVersion = $this->sanitizeString($device['hardware_version'] ?? null);
        $softwareVersion = $this->sanitizeString($device['software_version'] ?? null);

        $this->deviceRepo->upsertVersion($deviceId, $hardwareVersion, $softwareVersion);

        foreach ($device['endpoints'] ?? [] as $endpoint) {
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

        return true;
    }

    private function sanitizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if (strlen($value) > 255) {
            $value = substr($value, 0, 255);
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);

        return $value ?: null;
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
