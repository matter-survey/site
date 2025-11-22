<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\DatabaseService;
use Doctrine\DBAL\Connection;

class DeviceRepository
{
    private Connection $db;

    public function __construct(DatabaseService $databaseService)
    {
        $this->db = $databaseService->getConnection();
    }

    /**
     * @param bool $isNew Set to true if this was a new device (not an update)
     */
    public function upsertDevice(array $deviceData, bool &$isNew = false): int
    {
        // Check if device already exists
        $existing = $this->db->executeQuery(
            'SELECT id FROM devices WHERE vendor_id = :vendor_id AND product_id = :product_id',
            [
                'vendor_id' => $deviceData['vendor_id'],
                'product_id' => $deviceData['product_id'],
            ]
        )->fetchOne();

        $isNew = ($existing === false);

        $result = $this->db->executeQuery('
            INSERT INTO devices (vendor_id, vendor_name, vendor_fk, product_id, product_name, last_seen, submission_count)
            VALUES (:vendor_id, :vendor_name, :vendor_fk, :product_id, :product_name, CURRENT_TIMESTAMP, 1)
            ON CONFLICT(vendor_id, product_id) DO UPDATE SET
                vendor_name = COALESCE(excluded.vendor_name, devices.vendor_name),
                vendor_fk = COALESCE(excluded.vendor_fk, devices.vendor_fk),
                product_name = COALESCE(excluded.product_name, devices.product_name),
                last_seen = CURRENT_TIMESTAMP,
                submission_count = devices.submission_count + 1
            RETURNING id
        ', [
            'vendor_id' => $deviceData['vendor_id'],
            'vendor_name' => $deviceData['vendor_name'],
            'vendor_fk' => $deviceData['vendor_fk'] ?? null,
            'product_id' => $deviceData['product_id'],
            'product_name' => $deviceData['product_name'],
        ]);

        return (int) $result->fetchOne();
    }

    public function upsertVersion(int $deviceId, ?string $hardwareVersion, ?string $softwareVersion): void
    {
        $this->db->executeStatement('
            INSERT INTO device_versions (device_id, hardware_version, software_version, last_seen, count)
            VALUES (:device_id, :hardware_version, :software_version, CURRENT_TIMESTAMP, 1)
            ON CONFLICT(device_id, hardware_version, software_version) DO UPDATE SET
                last_seen = CURRENT_TIMESTAMP,
                count = device_versions.count + 1
        ', [
            'device_id' => $deviceId,
            'hardware_version' => $hardwareVersion,
            'software_version' => $softwareVersion,
        ]);
    }

    public function upsertEndpoint(int $deviceId, array $endpointData): void
    {
        $this->db->executeStatement('
            INSERT INTO device_endpoints (device_id, endpoint_id, device_types, clusters, has_binding_cluster)
            VALUES (:device_id, :endpoint_id, :device_types, :clusters, :has_binding_cluster)
            ON CONFLICT(device_id, endpoint_id) DO UPDATE SET
                device_types = excluded.device_types,
                clusters = excluded.clusters,
                has_binding_cluster = excluded.has_binding_cluster
        ', [
            'device_id' => $deviceId,
            'endpoint_id' => $endpointData['endpoint_id'],
            'device_types' => json_encode($endpointData['device_types'] ?? []),
            'clusters' => json_encode($endpointData['clusters'] ?? []),
            'has_binding_cluster' => $endpointData['has_binding_cluster'] ? 1 : 0,
        ]);
    }

    public function getAllDevices(int $limit = 100, int $offset = 0): array
    {
        return $this->db->executeQuery('
            SELECT * FROM device_summary
            ORDER BY submission_count DESC, last_seen DESC
            LIMIT :limit OFFSET :offset
        ', [
            'limit' => $limit,
            'offset' => $offset,
        ], [
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
            'offset' => \Doctrine\DBAL\ParameterType::INTEGER,
        ])->fetchAllAssociative();
    }

    public function getDeviceCount(): int
    {
        return (int) $this->db->executeQuery('SELECT COUNT(*) FROM devices')->fetchOne();
    }

    public function getDevice(int $id): ?array
    {
        $result = $this->db->executeQuery(
            'SELECT * FROM device_summary WHERE id = :id',
            ['id' => $id]
        )->fetchAssociative();

        return $result ?: null;
    }

    public function getDeviceEndpoints(int $deviceId): array
    {
        $rows = $this->db->executeQuery('
            SELECT endpoint_id, device_types, clusters, has_binding_cluster
            FROM device_endpoints
            WHERE device_id = :device_id
            ORDER BY endpoint_id
        ', ['device_id' => $deviceId])->fetchAllAssociative();

        $endpoints = [];
        foreach ($rows as $row) {
            $row['device_types'] = json_decode($row['device_types'], true);
            $row['clusters'] = json_decode($row['clusters'], true);
            $row['has_binding_cluster'] = (bool) $row['has_binding_cluster'];
            $endpoints[] = $row;
        }

        return $endpoints;
    }

    public function getDeviceVersions(int $deviceId): array
    {
        return $this->db->executeQuery('
            SELECT hardware_version, software_version, count, first_seen, last_seen
            FROM device_versions
            WHERE device_id = :device_id
            ORDER BY count DESC
        ', ['device_id' => $deviceId])->fetchAllAssociative();
    }

    public function searchDevices(string $query, int $limit = 50): array
    {
        return $this->db->executeQuery('
            SELECT * FROM device_summary
            WHERE vendor_name LIKE :query
               OR product_name LIKE :query
               OR CAST(vendor_id AS TEXT) LIKE :query
               OR CAST(product_id AS TEXT) LIKE :query
            ORDER BY submission_count DESC
            LIMIT :limit
        ', [
            'query' => "%$query%",
            'limit' => $limit,
        ], [
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
        ])->fetchAllAssociative();
    }

    /**
     * Get devices by vendor FK.
     */
    public function getDevicesByVendor(int $vendorFk, int $limit = 100, int $offset = 0): array
    {
        return $this->db->executeQuery('
            SELECT * FROM device_summary
            WHERE vendor_fk = :vendor_fk
            ORDER BY submission_count DESC, last_seen DESC
            LIMIT :limit OFFSET :offset
        ', [
            'vendor_fk' => $vendorFk,
            'limit' => $limit,
            'offset' => $offset,
        ], [
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
            'offset' => \Doctrine\DBAL\ParameterType::INTEGER,
        ])->fetchAllAssociative();
    }

    /**
     * Count devices by vendor FK.
     */
    public function getDeviceCountByVendor(int $vendorFk): int
    {
        return (int) $this->db->executeQuery(
            'SELECT COUNT(*) FROM devices WHERE vendor_fk = :vendor_fk',
            ['vendor_fk' => $vendorFk]
        )->fetchOne();
    }
}
