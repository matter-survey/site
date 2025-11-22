<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\DatabaseService;
use Doctrine\DBAL\Connection;

class DeviceRepository
{
    private const BINDING_CLUSTER_ID = 30; // 0x001E

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
            'SELECT id FROM products WHERE vendor_id = :vendor_id AND product_id = :product_id',
            [
                'vendor_id' => $deviceData['vendor_id'],
                'product_id' => $deviceData['product_id'],
            ]
        )->fetchOne();

        $isNew = ($existing === false);

        $result = $this->db->executeQuery('
            INSERT INTO products (vendor_id, vendor_name, vendor_fk, product_id, product_name, last_seen, submission_count)
            VALUES (:vendor_id, :vendor_name, :vendor_fk, :product_id, :product_name, CURRENT_TIMESTAMP, 1)
            ON CONFLICT(vendor_id, product_id) DO UPDATE SET
                vendor_name = COALESCE(excluded.vendor_name, products.vendor_name),
                vendor_fk = COALESCE(excluded.vendor_fk, products.vendor_fk),
                product_name = COALESCE(excluded.product_name, products.product_name),
                last_seen = CURRENT_TIMESTAMP,
                submission_count = products.submission_count + 1
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
            INSERT INTO product_versions (device_id, hardware_version, software_version, last_seen, count)
            VALUES (:device_id, :hardware_version, :software_version, CURRENT_TIMESTAMP, 1)
            ON CONFLICT(device_id, hardware_version, software_version) DO UPDATE SET
                last_seen = CURRENT_TIMESTAMP,
                count = product_versions.count + 1
        ', [
            'device_id' => $deviceId,
            'hardware_version' => $hardwareVersion,
            'software_version' => $softwareVersion,
        ]);
    }

    public function upsertEndpoint(int $deviceId, array $endpointData): void
    {
        $this->db->executeStatement('
            INSERT INTO product_endpoints (device_id, endpoint_id, device_types, server_clusters, client_clusters)
            VALUES (:device_id, :endpoint_id, :device_types, :server_clusters, :client_clusters)
            ON CONFLICT(device_id, endpoint_id) DO UPDATE SET
                device_types = excluded.device_types,
                server_clusters = excluded.server_clusters,
                client_clusters = excluded.client_clusters
        ', [
            'device_id' => $deviceId,
            'endpoint_id' => $endpointData['endpoint_id'],
            'device_types' => json_encode($endpointData['device_types'] ?? []),
            'server_clusters' => json_encode($endpointData['server_clusters'] ?? []),
            'client_clusters' => json_encode($endpointData['client_clusters'] ?? []),
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
        return (int) $this->db->executeQuery('SELECT COUNT(*) FROM products')->fetchOne();
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
            SELECT endpoint_id, device_types, server_clusters, client_clusters
            FROM product_endpoints
            WHERE device_id = :device_id
            ORDER BY endpoint_id
        ', ['device_id' => $deviceId])->fetchAllAssociative();

        $endpoints = [];
        foreach ($rows as $row) {
            $row['device_types'] = json_decode($row['device_types'], true);
            $row['server_clusters'] = json_decode($row['server_clusters'], true);
            $row['client_clusters'] = json_decode($row['client_clusters'], true);
            // Derive binding support from either server or client clusters
            $row['has_binding_cluster'] = \in_array(self::BINDING_CLUSTER_ID, $row['server_clusters'] ?? [], true)
                || \in_array(self::BINDING_CLUSTER_ID, $row['client_clusters'] ?? [], true);
            $endpoints[] = $row;
        }

        return $endpoints;
    }

    public function getDeviceVersions(int $deviceId): array
    {
        return $this->db->executeQuery('
            SELECT hardware_version, software_version, count, first_seen, last_seen
            FROM product_versions
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
            'SELECT COUNT(*) FROM products WHERE vendor_fk = :vendor_fk',
            ['vendor_fk' => $vendorFk]
        )->fetchOne();
    }

    /**
     * Get cluster statistics from the cluster_stats view.
     * Returns cluster_id, cluster_type (server/client), and product_count.
     */
    public function getClusterStats(): array
    {
        return $this->db->executeQuery('
            SELECT cluster_id, cluster_type, product_count
            FROM cluster_stats
            ORDER BY product_count DESC
        ')->fetchAllAssociative();
    }

    /**
     * Get device type distribution across all products.
     * Device types are stored as JSON arrays of objects with 'id' and 'revision' fields.
     */
    public function getDeviceTypeStats(): array
    {
        return $this->db->executeQuery('
            SELECT
                json_extract(json_each.value, "$.id") as device_type_id,
                COUNT(DISTINCT pe.device_id) as product_count
            FROM product_endpoints pe, json_each(pe.device_types)
            WHERE json_extract(json_each.value, "$.id") IS NOT NULL
            GROUP BY json_extract(json_each.value, "$.id")
            ORDER BY product_count DESC
        ')->fetchAllAssociative();
    }

    /**
     * Get devices that implement a specific device type.
     */
    public function getDevicesByDeviceType(int $deviceTypeId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->executeQuery('
            SELECT DISTINCT ds.*
            FROM device_summary ds
            JOIN product_endpoints pe ON ds.id = pe.device_id
            WHERE EXISTS (
                SELECT 1 FROM json_each(pe.device_types)
                WHERE json_extract(value, "$.id") = :device_type_id
            )
            ORDER BY ds.submission_count DESC, ds.last_seen DESC
            LIMIT :limit OFFSET :offset
        ', [
            'device_type_id' => $deviceTypeId,
            'limit' => $limit,
            'offset' => $offset,
        ], [
            'device_type_id' => \Doctrine\DBAL\ParameterType::INTEGER,
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
            'offset' => \Doctrine\DBAL\ParameterType::INTEGER,
        ])->fetchAllAssociative();
    }

    /**
     * Count devices that implement a specific device type.
     */
    public function countDevicesByDeviceType(int $deviceTypeId): int
    {
        return (int) $this->db->executeQuery('
            SELECT COUNT(DISTINCT pe.device_id)
            FROM product_endpoints pe
            WHERE EXISTS (
                SELECT 1 FROM json_each(pe.device_types)
                WHERE json_extract(value, "$.id") = :device_type_id
            )
        ', [
            'device_type_id' => $deviceTypeId,
        ], [
            'device_type_id' => \Doctrine\DBAL\ParameterType::INTEGER,
        ])->fetchOne();
    }

    /**
     * Get distribution by display category.
     */
    public function getCategoryDistribution(\App\Service\MatterRegistry $registry): array
    {
        $deviceTypeStats = $this->getDeviceTypeStats();
        $categoryStats = [];

        foreach ($deviceTypeStats as $dt) {
            $metadata = $registry->getDeviceTypeMetadata((int) $dt['device_type_id']);
            $displayCategory = $metadata['displayCategory'] ?? 'Unknown';

            if (!isset($categoryStats[$displayCategory])) {
                $categoryStats[$displayCategory] = 0;
            }
            $categoryStats[$displayCategory] += (int) $dt['product_count'];
        }

        arsort($categoryStats);
        return $categoryStats;
    }

    /**
     * Get distribution by Matter spec version.
     */
    public function getSpecVersionDistribution(\App\Service\MatterRegistry $registry): array
    {
        $deviceTypeStats = $this->getDeviceTypeStats();
        $versionStats = [];

        foreach ($deviceTypeStats as $dt) {
            $metadata = $registry->getDeviceTypeMetadata((int) $dt['device_type_id']);
            $specVersion = $metadata['specVersion'] ?? 'Unknown';

            if (!isset($versionStats[$specVersion])) {
                $versionStats[$specVersion] = 0;
            }
            $versionStats[$specVersion] += (int) $dt['product_count'];
        }

        uksort($versionStats, 'version_compare');
        return $versionStats;
    }

    /**
     * Get top vendors by device count.
     */
    public function getTopVendors(int $limit = 10): array
    {
        return $this->db->executeQuery('
            SELECT v.id, v.name, v.slug, v.spec_id, v.device_count
            FROM vendors v
            WHERE v.device_count > 0
            ORDER BY v.device_count DESC
            LIMIT :limit
        ', ['limit' => $limit], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchAllAssociative();
    }

    /**
     * Get most recently discovered devices.
     */
    public function getRecentDevices(int $limit = 5): array
    {
        return $this->db->executeQuery('
            SELECT * FROM device_summary
            ORDER BY first_seen DESC
            LIMIT :limit
        ', ['limit' => $limit], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchAllAssociative();
    }

    /**
     * Get cluster co-occurrence (which server clusters appear together).
     */
    public function getClusterCoOccurrence(int $limit = 15): array
    {
        return $this->db->executeQuery('
            SELECT
                c1.value as cluster_a,
                c2.value as cluster_b,
                COUNT(DISTINCT pe.device_id) as co_occurrence_count
            FROM product_endpoints pe,
                 json_each(pe.server_clusters) c1,
                 json_each(pe.server_clusters) c2
            WHERE c1.value < c2.value
            GROUP BY c1.value, c2.value
            ORDER BY co_occurrence_count DESC
            LIMIT :limit
        ', ['limit' => $limit], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchAllAssociative();
    }

    /**
     * Get devices that support binding (have cluster 30).
     */
    public function getBindingCapableDevices(int $limit = 50): array
    {
        return $this->db->executeQuery('
            SELECT * FROM device_summary
            WHERE supports_binding = 1
            ORDER BY submission_count DESC
            LIMIT :limit
        ', ['limit' => $limit], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchAllAssociative();
    }

    /**
     * Get binding support breakdown by category.
     * Device types are stored as JSON arrays of objects with 'id' and 'revision' fields.
     */
    public function getBindingByCategory(\App\Service\MatterRegistry $registry): array
    {
        $rows = $this->db->executeQuery('
            SELECT
                pe.device_id,
                json_extract(json_each.value, "$.id") as device_type_id,
                MAX(CASE WHEN EXISTS (
                    SELECT 1 FROM json_each(pe.server_clusters) WHERE value = 30
                ) OR EXISTS (
                    SELECT 1 FROM json_each(pe.client_clusters) WHERE value = 30
                ) THEN 1 ELSE 0 END) as has_binding
            FROM product_endpoints pe, json_each(pe.device_types)
            WHERE json_extract(json_each.value, "$.id") IS NOT NULL
            GROUP BY pe.device_id, json_extract(json_each.value, "$.id")
        ')->fetchAllAssociative();

        $categoryStats = [];
        foreach ($rows as $row) {
            $metadata = $registry->getDeviceTypeMetadata((int) $row['device_type_id']);
            $displayCategory = $metadata['displayCategory'] ?? 'Unknown';

            if (!isset($categoryStats[$displayCategory])) {
                $categoryStats[$displayCategory] = ['total' => 0, 'with_binding' => 0];
            }
            $categoryStats[$displayCategory]['total']++;
            if ($row['has_binding']) {
                $categoryStats[$displayCategory]['with_binding']++;
            }
        }

        // Calculate percentages
        foreach ($categoryStats as &$stat) {
            $stat['percentage'] = $stat['total'] > 0
                ? round(($stat['with_binding'] / $stat['total']) * 100, 1)
                : 0;
        }

        uasort($categoryStats, fn($a, $b) => $b['percentage'] <=> $a['percentage']);
        return $categoryStats;
    }

    /**
     * Get products with multiple software/hardware versions (indicating OTA activity).
     */
    public function getProductsWithMultipleVersions(int $limit = 30): array
    {
        return $this->db->executeQuery('
            SELECT
                p.id,
                p.vendor_name,
                p.product_name,
                v.slug as vendor_slug,
                COUNT(DISTINCT pv.software_version) as software_version_count,
                COUNT(DISTINCT pv.hardware_version) as hardware_version_count,
                GROUP_CONCAT(DISTINCT pv.software_version) as software_versions
            FROM products p
            LEFT JOIN vendors v ON p.vendor_fk = v.id
            JOIN product_versions pv ON p.id = pv.device_id
            GROUP BY p.id
            HAVING software_version_count > 1 OR hardware_version_count > 1
            ORDER BY software_version_count DESC, hardware_version_count DESC
            LIMIT :limit
        ', ['limit' => $limit], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchAllAssociative();
    }

    /**
     * Get overall version statistics.
     */
    public function getVersionStats(): array
    {
        $totalProducts = (int) $this->db->executeQuery('SELECT COUNT(*) FROM products')->fetchOne();
        $productsWithVersions = (int) $this->db->executeQuery('
            SELECT COUNT(DISTINCT device_id) FROM product_versions
        ')->fetchOne();
        $uniqueSoftwareVersions = (int) $this->db->executeQuery('
            SELECT COUNT(DISTINCT software_version) FROM product_versions WHERE software_version IS NOT NULL
        ')->fetchOne();
        $uniqueHardwareVersions = (int) $this->db->executeQuery('
            SELECT COUNT(DISTINCT hardware_version) FROM product_versions WHERE hardware_version IS NOT NULL
        ')->fetchOne();

        return [
            'total_products' => $totalProducts,
            'products_with_versions' => $productsWithVersions,
            'unique_software_versions' => $uniqueSoftwareVersions,
            'unique_hardware_versions' => $uniqueHardwareVersions,
        ];
    }
}
