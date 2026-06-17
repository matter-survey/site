<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DatabaseService;
use App\Service\TelemetryService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Direct tests for TelemetryService — focuses on logic that the
 * controller-level ApiControllerTest cannot reach: v2/v3 normalization,
 * bridged-node filtering, connectivity extraction, string sanitization,
 * and rollback behavior.
 */
final class TelemetryServiceTest extends KernelTestCase
{
    private TelemetryService $service;
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->service = $container->get(TelemetryService::class);
        $this->db = $container->get(DatabaseService::class)->getConnection();
    }

    private function uuid(string $suffix): string
    {
        return '550e8400-e29b-41d4-a716-44665544'.str_pad($suffix, 4, '0', STR_PAD_LEFT);
    }

    public function testProcessSubmissionRejectsMissingInstallationId(): void
    {
        $result = $this->service->processSubmission(['devices' => []]);

        $this->assertFalse($result['success']);
        $this->assertSame('Missing installation_id', $result['error']);
    }

    public function testProcessSubmissionRejectsInvalidUuid(): void
    {
        $result = $this->service->processSubmission([
            'installation_id' => 'not-a-uuid',
            'devices' => [],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid installation_id format', $result['error']);
    }

    public function testProcessSubmissionRejectsMissingDevicesArray(): void
    {
        $result = $this->service->processSubmission([
            'installation_id' => $this->uuid('1001'),
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('Missing or invalid devices array', $result['error']);
    }

    public function testProcessSubmissionSkipsDevicesWithoutVendorOrProductId(): void
    {
        $result = $this->service->processSubmission([
            'installation_id' => $this->uuid('1002'),
            'devices' => [
                ['vendor_name' => 'Anonymous'],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['devices_processed']);
    }

    public function testProcessSubmissionV2EndpointStoresClusterIds(): void
    {
        $installationId = $this->uuid('1003');

        $result = $this->service->processSubmission([
            'installation_id' => $installationId,
            'schema_version' => 2,
            'devices' => [
                [
                    'vendor_id' => 0x2001,
                    'vendor_name' => 'Acme',
                    'product_id' => 0x0042,
                    'product_name' => 'Gizmo',
                    'hardware_version' => '1.0',
                    'software_version' => '2.0',
                    'endpoints' => [
                        [
                            'endpoint_id' => 1,
                            'device_types' => [256],
                            'server_clusters' => [6, 8, 29],
                            'client_clusters' => [],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['devices_processed']);

        $row = $this->db->fetchAssociative(
            'SELECT server_clusters, server_cluster_details FROM product_endpoints
             WHERE device_id IN (SELECT id FROM products WHERE vendor_id = ? AND product_id = ?)',
            [0x2001, 0x0042]
        );
        $this->assertIsArray($row);
        $this->assertSame([6, 8, 29], json_decode((string) $row['server_clusters'], true));
        $this->assertNull($row['server_cluster_details']);
    }

    public function testProcessSubmissionV3EndpointPreservesClusterDetails(): void
    {
        $installationId = $this->uuid('1004');

        $result = $this->service->processSubmission([
            'installation_id' => $installationId,
            'schema_version' => 3,
            'devices' => [
                [
                    'vendor_id' => 0x2002,
                    'product_id' => 0x0043,
                    'product_name' => 'V3 Gizmo',
                    'hardware_version' => '1.0',
                    'software_version' => '3.0',
                    'endpoints' => [
                        [
                            'endpoint_id' => 1,
                            'device_types' => [['id' => 256, 'revision' => 1]],
                            'server_clusters' => [
                                ['id' => 6, 'feature_map' => 0, 'accepted_command_list' => [0, 1, 2], 'attribute_list' => [0]],
                                ['id' => 29, 'feature_map' => 0],
                            ],
                            'client_clusters' => [],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['success']);

        $row = $this->db->fetchAssociative(
            'SELECT server_clusters, server_cluster_details FROM product_endpoints
             WHERE device_id IN (SELECT id FROM products WHERE vendor_id = ? AND product_id = ?)',
            [0x2002, 0x0043]
        );
        $this->assertIsArray($row);
        $this->assertSame([6, 29], json_decode((string) $row['server_clusters'], true), 'v3 normalizer must extract ids for legacy column');
        $details = json_decode((string) $row['server_cluster_details'], true);
        $this->assertCount(2, $details);
        $this->assertSame([0, 1, 2], $details[0]['accepted_command_list']);
    }

    public function testProcessSubmissionSkipsBridgedNodeEndpoint(): void
    {
        $installationId = $this->uuid('1005');

        $result = $this->service->processSubmission([
            'installation_id' => $installationId,
            'devices' => [
                [
                    'vendor_id' => 0x2003,
                    'product_id' => 0x0044,
                    'product_name' => 'Bridge',
                    'endpoints' => [
                        ['endpoint_id' => 0, 'device_types' => [22], 'server_clusters' => [29], 'client_clusters' => []],
                        ['endpoint_id' => 5, 'device_types' => [19], 'server_clusters' => [6], 'client_clusters' => []],
                        ['endpoint_id' => 6, 'device_types' => [256], 'server_clusters' => [6, 29], 'client_clusters' => []],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['success']);

        $rows = $this->db->fetchAllAssociative(
            'SELECT endpoint_id FROM product_endpoints
             WHERE device_id IN (SELECT id FROM products WHERE vendor_id = ? AND product_id = ?)
             ORDER BY endpoint_id',
            [0x2003, 0x0044]
        );
        $endpointIds = array_map(static fn (array $r): int => (int) $r['endpoint_id'], $rows);
        $this->assertNotContains(5, $endpointIds, 'Bridged Node (device type 19) endpoint must be skipped');
        $this->assertContains(6, $endpointIds);
    }

    public function testProcessSubmissionExtractsConnectivityTypes(): void
    {
        $installationId = $this->uuid('1006');

        $this->service->processSubmission([
            'installation_id' => $installationId,
            'devices' => [
                [
                    'vendor_id' => 0x2004,
                    'product_id' => 0x0045,
                    'endpoints' => [
                        // Thread + WiFi, plus an irrelevant cluster
                        ['endpoint_id' => 0, 'device_types' => [22], 'server_clusters' => [53, 54, 29], 'client_clusters' => []],
                    ],
                ],
            ],
        ]);

        $connectivity = $this->db->fetchOne(
            'SELECT connectivity_types FROM products WHERE vendor_id = ? AND product_id = ?',
            [0x2004, 0x0045]
        );
        $types = json_decode((string) $connectivity, true);
        $this->assertSame(['thread', 'wifi'], $types, 'connectivity types must be unique & sorted');
    }

    public function testProcessSubmissionV3ConnectivityFromClusterObjects(): void
    {
        $installationId = $this->uuid('1007');

        $this->service->processSubmission([
            'installation_id' => $installationId,
            'schema_version' => 3,
            'devices' => [
                [
                    'vendor_id' => 0x2005,
                    'product_id' => 0x0046,
                    'endpoints' => [
                        [
                            'endpoint_id' => 0,
                            'device_types' => [['id' => 22]],
                            'server_clusters' => [
                                ['id' => 55, 'feature_map' => 0],
                                ['id' => 29, 'feature_map' => 0],
                            ],
                            'client_clusters' => [],
                        ],
                    ],
                ],
            ],
        ]);

        $connectivity = $this->db->fetchOne(
            'SELECT connectivity_types FROM products WHERE vendor_id = ? AND product_id = ?',
            [0x2005, 0x0046]
        );
        $this->assertSame(['ethernet'], json_decode((string) $connectivity, true));
    }

    public function testProcessSubmissionSanitizesProductNameLengthAndControlChars(): void
    {
        $installationId = $this->uuid('1008');
        $longName = str_repeat('x', 400);
        $dirty = "Hello\x00World\x07";

        $this->service->processSubmission([
            'installation_id' => $installationId,
            'devices' => [
                [
                    'vendor_id' => 0x2006,
                    'product_id' => 0x0047,
                    'vendor_name' => $dirty,
                    'product_name' => $longName,
                ],
            ],
        ]);

        $row = $this->db->fetchAssociative(
            'SELECT vendor_name, product_name FROM products WHERE vendor_id = ? AND product_id = ?',
            [0x2006, 0x0047]
        );
        $this->assertNotFalse($row);
        $this->assertSame('HelloWorld', $row['vendor_name']);
        $this->assertSame(255, strlen((string) $row['product_name']));
    }

    public function testProcessSubmissionIncrementsInstallationCountOnRepeat(): void
    {
        $installationId = $this->uuid('1009');
        $payload = [
            'installation_id' => $installationId,
            'devices' => [
                ['vendor_id' => 0x2007, 'product_id' => 0x0048],
            ],
        ];

        $this->service->processSubmission($payload);
        $this->service->processSubmission($payload);

        $count = $this->db->fetchOne(
            'SELECT submission_count FROM installations WHERE installation_id = ?',
            [$installationId]
        );
        $this->assertSame(2, (int) $count);
    }

    public function testGetStatsReturnsExpectedKeys(): void
    {
        $stats = $this->service->getStats();
        $this->assertSame(
            ['total_devices', 'total_vendors', 'total_installations', 'total_submissions', 'bindable_devices', 'groups_devices', 'scenes_devices'],
            array_keys($stats),
        );
        foreach ($stats as $value) {
            $this->assertIsInt($value);
        }
    }
}
