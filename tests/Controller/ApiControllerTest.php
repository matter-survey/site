<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class ApiControllerTest extends WebTestCase
{
    public function testApiDocsRedirect(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/');

        // /api/ should redirect to docs
        $this->assertResponseRedirects('/api/docs.html');
    }

    public function testSubmitWithEmptyBody(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Empty request body', $response['error']);
    }

    public function testSubmitWithInvalidJson(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], 'not valid json {');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Invalid JSON', $response['error']);
    }

    public function testSubmitWithMissingInstallationId(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['devices' => []]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('installation_id', $response['error']);
    }

    public function testSubmitWithInvalidInstallationIdFormat(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => 'not-a-uuid',
            'devices' => [],
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('installation_id', $response['error']);
    }

    public function testSubmitWithMissingDevices(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440000',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('devices', $response['error']);
    }

    public function testSubmitWithValidEmptyDevices(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440000',
            'devices' => [],
        ]));

        $this->assertResponseIsSuccessful();

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('ok', $response['status']);
        $this->assertEquals(0, $response['devices_processed']);
    }

    public function testSubmitWithValidDevice(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440001',
            'schema_version' => 2,
            'devices' => [
                [
                    'vendor_id' => 0x1234,
                    'vendor_name' => 'Test Vendor',
                    'product_id' => 0x5678,
                    'product_name' => 'Test Product',
                    'hardware_version' => '1.0',
                    'software_version' => '2.0',
                    'endpoints' => [
                        [
                            'endpoint_id' => 1,
                            'device_types' => [['id' => 0x0100, 'revision' => 1]],
                            'server_clusters' => [0x0006, 0x0008, 0x001E], // OnOff, LevelControl, Binding
                            'client_clusters' => [],
                        ],
                    ],
                ],
            ],
        ]));

        $this->assertResponseIsSuccessful();

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('ok', $response['status']);
        $this->assertEquals(1, $response['devices_processed']);
    }

    public function testSubmitMethodNotAllowed(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/submit');

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testSubmitSameDeviceDifferentVersionsCreatesSeparateEndpoints(): void
    {
        $client = static::createClient();

        // Submit version 1.0
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440010',
            'schema_version' => 2,
            'devices' => [
                [
                    'vendor_id' => 0x9999,
                    'vendor_name' => 'Version Test Vendor',
                    'product_id' => 0x0001,
                    'product_name' => 'Version Test Product',
                    'hardware_version' => '1.0',
                    'software_version' => '1.0.0',
                    'endpoints' => [
                        [
                            'endpoint_id' => 1,
                            'device_types' => [['id' => 256, 'revision' => 1]],
                            'server_clusters' => [6, 8, 29], // OnOff, LevelControl, Descriptor
                            'client_clusters' => [],
                        ],
                    ],
                ],
            ],
        ]));
        $this->assertResponseIsSuccessful();

        // Submit version 2.0 with additional cluster (Binding)
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440010',
            'schema_version' => 2,
            'devices' => [
                [
                    'vendor_id' => 0x9999,
                    'vendor_name' => 'Version Test Vendor',
                    'product_id' => 0x0001,
                    'product_name' => 'Version Test Product',
                    'hardware_version' => '1.0',
                    'software_version' => '2.0.0',
                    'endpoints' => [
                        [
                            'endpoint_id' => 1,
                            'device_types' => [['id' => 256, 'revision' => 1]],
                            'server_clusters' => [6, 8, 29, 30], // Added Binding cluster
                            'client_clusters' => [6], // Added OnOff client
                        ],
                    ],
                ],
            ],
        ]));
        $this->assertResponseIsSuccessful();

        // Verify both versions exist by checking the device page
        $crawler = $client->request('GET', '/');
        $this->assertResponseIsSuccessful();

        // Find the device link and visit it
        $deviceLink = $crawler->filter('a:contains("Version Test Product")');
        if ($deviceLink->count() > 0) {
            $client->click($deviceLink->link());
            $this->assertResponseIsSuccessful();

            // Verify both versions are displayed
            $content = $client->getResponse()->getContent();
            $this->assertStringContainsString('1.0.0', $content);
            $this->assertStringContainsString('2.0.0', $content);
        }
    }

    public function testSubmitSameDeviceVersionUpdatesExistingEndpoint(): void
    {
        $client = static::createClient();

        // First submission
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440011',
            'schema_version' => 2,
            'devices' => [
                [
                    'vendor_id' => 0x8888,
                    'vendor_name' => 'Update Test Vendor',
                    'product_id' => 0x0002,
                    'product_name' => 'Update Test Product',
                    'hardware_version' => '1.0',
                    'software_version' => '1.0.0',
                    'endpoints' => [
                        [
                            'endpoint_id' => 1,
                            'device_types' => [['id' => 256, 'revision' => 1]],
                            'server_clusters' => [6, 29],
                            'client_clusters' => [],
                        ],
                    ],
                ],
            ],
        ]));
        $this->assertResponseIsSuccessful();
        $response1 = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(1, $response1['devices_processed']);

        // Second submission with same version - should update, not duplicate
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440012', // Different installation
            'schema_version' => 2,
            'devices' => [
                [
                    'vendor_id' => 0x8888,
                    'vendor_name' => 'Update Test Vendor',
                    'product_id' => 0x0002,
                    'product_name' => 'Update Test Product',
                    'hardware_version' => '1.0',
                    'software_version' => '1.0.0',
                    'endpoints' => [
                        [
                            'endpoint_id' => 1,
                            'device_types' => [['id' => 256, 'revision' => 1]],
                            'server_clusters' => [6, 29],
                            'client_clusters' => [],
                        ],
                    ],
                ],
            ],
        ]));
        $this->assertResponseIsSuccessful();
        $response2 = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(1, $response2['devices_processed']);

        // Device page should show the endpoint only once for this version
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('a:contains("Update Test Product")');
        if ($deviceLink->count() > 0) {
            $client->click($deviceLink->link());
            $this->assertResponseIsSuccessful();

            // Count occurrences of "Endpoint 1" - should appear only once per version
            $content = $client->getResponse()->getContent();
            $this->assertEquals(1, substr_count($content, 'SW: 1.0.0'));
        }
    }

    public function testSubmitDeviceWithClientClustersStoresCorrectly(): void
    {
        $client = static::createClient();

        // Submit device with both server and client clusters
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440013',
            'schema_version' => 2,
            'devices' => [
                [
                    'vendor_id' => 0x7777,
                    'vendor_name' => 'Client Cluster Test Vendor',
                    'product_id' => 0x0003,
                    'product_name' => 'Motion Sensor With Binding',
                    'hardware_version' => '1.0',
                    'software_version' => '1.0.0',
                    'endpoints' => [
                        [
                            'endpoint_id' => 1,
                            'device_types' => [['id' => 263, 'revision' => 1]], // Occupancy Sensor
                            'server_clusters' => [29, 30, 1030], // Descriptor, Binding, OccupancySensing
                            'client_clusters' => [6, 8], // OnOff client, LevelControl client
                        ],
                    ],
                ],
            ],
        ]));
        $this->assertResponseIsSuccessful();

        // Verify client clusters are displayed on device page
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('a:contains("Motion Sensor With Binding")');
        if ($deviceLink->count() > 0) {
            $client->click($deviceLink->link());
            $this->assertResponseIsSuccessful();

            $content = $client->getResponse()->getContent();
            // Should show "Client Clusters" section
            $this->assertStringContainsString('Client Clusters', $content);
            // Should show OnOff in client clusters
            $this->assertStringContainsString('On/Off', $content);
        }
    }

    public function testVersionDiffShowsAddedAndRemovedClusters(): void
    {
        $client = static::createClient();

        // Submit version 1.0 with basic clusters
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440020',
            'schema_version' => 2,
            'devices' => [
                [
                    'vendor_id' => 0x6666,
                    'vendor_name' => 'Diff Test Vendor',
                    'product_id' => 0x0010,
                    'product_name' => 'Diff Test Light',
                    'hardware_version' => '1.0',
                    'software_version' => '1.0.0',
                    'endpoints' => [
                        [
                            'endpoint_id' => 1,
                            'device_types' => [['id' => 256, 'revision' => 1]],
                            'server_clusters' => [6, 8, 29], // OnOff, LevelControl, Descriptor
                            'client_clusters' => [],
                        ],
                    ],
                ],
            ],
        ]));
        $this->assertResponseIsSuccessful();

        // Submit version 2.0 with added Binding cluster and OnOff client
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440020',
            'schema_version' => 2,
            'devices' => [
                [
                    'vendor_id' => 0x6666,
                    'vendor_name' => 'Diff Test Vendor',
                    'product_id' => 0x0010,
                    'product_name' => 'Diff Test Light',
                    'hardware_version' => '1.0',
                    'software_version' => '2.0.0',
                    'endpoints' => [
                        [
                            'endpoint_id' => 1,
                            'device_types' => [['id' => 256, 'revision' => 1]],
                            'server_clusters' => [6, 29, 30], // Removed LevelControl, added Binding
                            'client_clusters' => [6], // Added OnOff client
                        ],
                    ],
                ],
            ],
        ]));
        $this->assertResponseIsSuccessful();

        // Visit device page and verify diff is shown
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('a:contains("Diff Test Light")');
        $this->assertGreaterThan(0, $deviceLink->count(), 'Device link should exist');

        $crawler = $client->click($deviceLink->link());
        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();

        // Should show both versions
        $this->assertStringContainsString('2.0.0', $content);
        $this->assertStringContainsString('1.0.0', $content);

        // Should show diff section
        $this->assertStringContainsString('Changes from previous version', $content);

        // Should show added Binding cluster (server)
        $this->assertStringContainsString('Binding', $content);

        // Should show added OnOff client
        $this->assertStringContainsString('On/Off', $content);

        // Verify diff is on the NEWER version (2.0.0), not the older one
        // Find the version group containing 2.0.0 and verify it has the diff section
        $versionGroups = $crawler->filter('.version-group');
        $this->assertGreaterThanOrEqual(2, $versionGroups->count(), 'Should have at least 2 version groups');

        // First version group should be the latest (2.0.0) and should contain the diff
        $latestVersionGroup = $versionGroups->first();
        $this->assertStringContainsString('2.0.0', $latestVersionGroup->text());
        $this->assertStringContainsString('Changes from previous version', $latestVersionGroup->text());

        // Last version group (1.0.0) should NOT have a diff section (it's the baseline)
        $oldestVersionGroup = $versionGroups->last();
        $this->assertStringContainsString('1.0.0', $oldestVersionGroup->text());
        $this->assertStringNotContainsString('Changes from previous version', $oldestVersionGroup->text());
    }

    /**
     * Test that bridged node endpoints (device type 19) are filtered out.
     * These represent devices bridged from other protocols (Z-Wave, Zigbee, etc.)
     * and are user-specific, so we don't record them.
     */
    public function testSubmitFiltersBridgedNodeEndpoints(): void
    {
        $client = static::createClient();

        // Submit a bridge device with root, aggregator, and bridged node endpoints
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440030',
            'schema_version' => 2,
            'devices' => [
                [
                    'vendor_id' => 0x5555,
                    'vendor_name' => 'Bridge Test Vendor',
                    'product_id' => 0x0020,
                    'product_name' => 'Test Matter Bridge',
                    'hardware_version' => '1.0',
                    'software_version' => '1.0.0',
                    'endpoints' => [
                        // Endpoint 0: Root Node - should be recorded
                        [
                            'endpoint_id' => 0,
                            'device_types' => [['id' => 22, 'revision' => 1]], // Root Node
                            'server_clusters' => [29, 31, 40, 48, 49, 51, 60, 62, 63],
                            'client_clusters' => [],
                        ],
                        // Endpoint 1: Aggregator - should be recorded
                        [
                            'endpoint_id' => 1,
                            'device_types' => [['id' => 14, 'revision' => 1]], // Aggregator
                            'server_clusters' => [29, 30, 37],
                            'client_clusters' => [],
                        ],
                        // Endpoint 3: Bridged Node - should be FILTERED OUT
                        [
                            'endpoint_id' => 3,
                            'device_types' => [['id' => 19, 'revision' => 1]], // Bridged Node
                            'server_clusters' => [6, 29, 57],
                            'client_clusters' => [],
                        ],
                        // Endpoint 4: Another Bridged Node - should be FILTERED OUT
                        [
                            'endpoint_id' => 4,
                            'device_types' => [['id' => 19, 'revision' => 1]], // Bridged Node
                            'server_clusters' => [258, 29, 57], // Window Covering
                            'client_clusters' => [],
                        ],
                    ],
                ],
            ],
        ]));

        $this->assertResponseIsSuccessful();

        // Verify device page shows only non-bridged endpoints
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('a:contains("Test Matter Bridge")');
        $this->assertGreaterThan(0, $deviceLink->count(), 'Device link should exist');

        $crawler = $client->click($deviceLink->link());
        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();

        // Should show Root Node and Aggregator endpoints
        $this->assertStringContainsString('Endpoint 0', $content);
        $this->assertStringContainsString('Endpoint 1', $content);

        // Should NOT show Bridged Node endpoints (3 and 4)
        $this->assertStringNotContainsString('Endpoint 3', $content);
        $this->assertStringNotContainsString('Endpoint 4', $content);

        // Should show Root Node device type
        $this->assertStringContainsString('Root Node', $content);

        // Should NOT show "Bridged Node" as a device type (the endpoints were filtered)
        // Note: The text "Bridged Node" might still appear in other contexts,
        // so we count occurrences of "Endpoint 3" and "Endpoint 4" specifically
    }

    public function testSubmitCalculatesAndCachesDeviceScore(): void
    {
        $client = static::createClient();

        // Submit a device with proper endpoint data for scoring
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440040',
            'schema_version' => 2,
            'devices' => [
                [
                    'vendor_id' => 0x4444,
                    'vendor_name' => 'Score Test Vendor',
                    'product_id' => 0x0040,
                    'product_name' => 'Score Test Light',
                    'hardware_version' => '1.0',
                    'software_version' => '1.0.0',
                    'endpoints' => [
                        [
                            'endpoint_id' => 1,
                            'device_types' => [['id' => 256, 'revision' => 1]], // On/Off Light
                            'server_clusters' => [3, 4, 6, 29], // Identify, Groups, OnOff, Descriptor
                            'client_clusters' => [],
                        ],
                    ],
                ],
            ],
        ]));

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(1, $response['devices_processed']);

        // Verify score was cached by checking the database directly
        $container = static::getContainer();
        $connection = $container->get('doctrine.dbal.default_connection');

        // Find the device ID
        $deviceId = $connection->fetchOne(
            'SELECT id FROM products WHERE vendor_id = ? AND product_id = ?',
            [0x4444, 0x0040]
        );
        $this->assertNotFalse($deviceId, 'Device should exist in database');

        // Check that a score was cached for this device
        $score = $connection->fetchAssociative(
            'SELECT * FROM device_scores WHERE device_id = ?',
            [$deviceId]
        );
        $this->assertNotFalse($score, 'Device score should be cached after telemetry submission');
        $this->assertGreaterThan(0, $score['star_rating'], 'Star rating should be positive');
        $this->assertGreaterThanOrEqual(0, $score['overall_score'], 'Overall score should be non-negative');
    }

    /**
     * Test that v3 schema with rich cluster data is processed correctly.
     */
    public function testSubmitWithV3SchemaRichClusterData(): void
    {
        $client = static::createClient();

        // Submit device with v3 schema - clusters have full details
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440050',
            'schema_version' => 3,
            'devices' => [
                [
                    'vendor_id' => 0x3333,
                    'vendor_name' => 'V3 Schema Test Vendor',
                    'product_id' => 0x0050,
                    'product_name' => 'V3 Test Light',
                    'hardware_version' => '1.0',
                    'software_version' => '1.0.0',
                    'endpoints' => [
                        [
                            'endpoint_id' => 1,
                            'device_types' => [['id' => 256, 'revision' => 3]],
                            'server_clusters' => [
                                [
                                    'id' => 6,  // OnOff
                                    'feature_map' => 1,  // LT feature
                                    'attribute_list' => [0, 1, 65528, 65529, 65531, 65532, 65533],
                                    'accepted_command_list' => [0, 1, 2],  // Off, On, Toggle
                                    'generated_command_list' => [],
                                ],
                                [
                                    'id' => 8,  // LevelControl
                                    'feature_map' => 3,  // OO + LT features
                                    'attribute_list' => [0, 15, 17, 65528, 65529, 65531, 65532, 65533],
                                    'accepted_command_list' => [0, 1, 4, 5, 6, 7],
                                    'generated_command_list' => [],
                                ],
                                [
                                    'id' => 29,  // Descriptor
                                    'feature_map' => 0,
                                    'attribute_list' => [0, 1, 2, 3, 65528, 65529, 65531, 65532, 65533],
                                    'accepted_command_list' => [],
                                    'generated_command_list' => [],
                                ],
                            ],
                            'client_clusters' => [
                                [
                                    'id' => 6,  // OnOff client
                                    'feature_map' => 0,
                                    'attribute_list' => [65528, 65529, 65531, 65532, 65533],
                                    'accepted_command_list' => [],
                                    'generated_command_list' => [0, 1, 2],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]));

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(1, $response['devices_processed']);

        // Verify cluster details were stored in database
        $container = static::getContainer();
        $connection = $container->get('doctrine.dbal.default_connection');

        $deviceId = $connection->fetchOne(
            'SELECT id FROM products WHERE vendor_id = ? AND product_id = ?',
            [0x3333, 0x0050]
        );
        $this->assertNotFalse($deviceId, 'Device should exist in database');

        $endpoint = $connection->fetchAssociative(
            'SELECT * FROM product_endpoints WHERE device_id = ? AND endpoint_id = 1',
            [$deviceId]
        );
        $this->assertNotFalse($endpoint, 'Endpoint should exist');
        $this->assertEquals(3, $endpoint['schema_version'], 'Schema version should be 3');
        $this->assertNotNull($endpoint['server_cluster_details'], 'Server cluster details should be stored');
        $this->assertNotNull($endpoint['client_cluster_details'], 'Client cluster details should be stored');

        // Verify cluster IDs are extracted for backwards compatibility
        $serverClusters = json_decode($endpoint['server_clusters'], true);
        $this->assertContains(6, $serverClusters);
        $this->assertContains(8, $serverClusters);
        $this->assertContains(29, $serverClusters);

        // Verify full details are preserved
        $serverDetails = json_decode($endpoint['server_cluster_details'], true);
        $this->assertIsArray($serverDetails);
        $this->assertCount(3, $serverDetails);

        // Find OnOff cluster details
        $onOffDetails = null;
        foreach ($serverDetails as $detail) {
            if (6 === $detail['id']) {
                $onOffDetails = $detail;
                break;
            }
        }
        $this->assertNotNull($onOffDetails, 'OnOff cluster details should exist');
        $this->assertEquals(1, $onOffDetails['feature_map']);
        $this->assertContains(0, $onOffDetails['accepted_command_list']); // Off command
        $this->assertContains(1, $onOffDetails['accepted_command_list']); // On command
    }

    /**
     * Test that v3 schema auto-detection works even without explicit schema_version.
     */
    public function testSubmitAutoDetectsV3SchemaFromClusterFormat(): void
    {
        $client = static::createClient();

        // Submit without schema_version but with v3 cluster format
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440051',
            // No schema_version - should auto-detect v3 from cluster format
            'devices' => [
                [
                    'vendor_id' => 0x3334,
                    'vendor_name' => 'Auto V3 Vendor',
                    'product_id' => 0x0051,
                    'product_name' => 'Auto V3 Switch',
                    'hardware_version' => '1.0',
                    'software_version' => '1.0.0',
                    'endpoints' => [
                        [
                            'endpoint_id' => 1,
                            'device_types' => [['id' => 259, 'revision' => 2]],
                            'server_clusters' => [
                                ['id' => 6, 'feature_map' => 0, 'attribute_list' => [0, 65533], 'accepted_command_list' => [0, 1], 'generated_command_list' => []],
                            ],
                            'client_clusters' => [],
                        ],
                    ],
                ],
            ],
        ]));

        $this->assertResponseIsSuccessful();

        // Verify schema version was detected as 3
        $container = static::getContainer();
        $connection = $container->get('doctrine.dbal.default_connection');

        $endpoint = $connection->fetchAssociative(
            'SELECT schema_version, server_cluster_details FROM product_endpoints pe
             JOIN products p ON pe.device_id = p.id
             WHERE p.vendor_id = ? AND p.product_id = ?',
            [0x3334, 0x0051]
        );
        $this->assertEquals(3, $endpoint['schema_version'], 'Schema version should be auto-detected as 3');
        $this->assertNotNull($endpoint['server_cluster_details'], 'Cluster details should be stored');
    }

    /**
     * Test that v2 submission doesn't overwrite existing v3 cluster details.
     */
    public function testV2SubmissionPreservesExistingV3Details(): void
    {
        $client = static::createClient();

        // First: Submit with v3 schema
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440052',
            'schema_version' => 3,
            'devices' => [
                [
                    'vendor_id' => 0x3335,
                    'vendor_name' => 'Preserve V3 Vendor',
                    'product_id' => 0x0052,
                    'product_name' => 'Preserve V3 Bulb',
                    'hardware_version' => '1.0',
                    'software_version' => '1.0.0',
                    'endpoints' => [
                        [
                            'endpoint_id' => 1,
                            'device_types' => [['id' => 256, 'revision' => 2]],
                            'server_clusters' => [
                                ['id' => 6, 'feature_map' => 5, 'attribute_list' => [0, 1, 2], 'accepted_command_list' => [0, 1, 2], 'generated_command_list' => []],
                            ],
                            'client_clusters' => [],
                        ],
                    ],
                ],
            ],
        ]));
        $this->assertResponseIsSuccessful();

        // Second: Submit with v2 schema (same device, same version)
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440053',
            'schema_version' => 2,
            'devices' => [
                [
                    'vendor_id' => 0x3335,
                    'vendor_name' => 'Preserve V3 Vendor',
                    'product_id' => 0x0052,
                    'product_name' => 'Preserve V3 Bulb',
                    'hardware_version' => '1.0',
                    'software_version' => '1.0.0',
                    'endpoints' => [
                        [
                            'endpoint_id' => 1,
                            'device_types' => [['id' => 256, 'revision' => 2]],
                            'server_clusters' => [6],  // Just ID, no details
                            'client_clusters' => [],
                        ],
                    ],
                ],
            ],
        ]));
        $this->assertResponseIsSuccessful();

        // Verify v3 details were preserved
        $container = static::getContainer();
        $connection = $container->get('doctrine.dbal.default_connection');

        $endpoint = $connection->fetchAssociative(
            'SELECT schema_version, server_cluster_details FROM product_endpoints pe
             JOIN products p ON pe.device_id = p.id
             WHERE p.vendor_id = ? AND p.product_id = ?',
            [0x3335, 0x0052]
        );

        // Schema version should remain 3 (MAX behavior)
        $this->assertEquals(3, $endpoint['schema_version'], 'Schema version should remain 3 after v2 submission');

        // Cluster details should be preserved (COALESCE behavior)
        $this->assertNotNull($endpoint['server_cluster_details'], 'V3 cluster details should be preserved');
        $details = json_decode($endpoint['server_cluster_details'], true);
        $this->assertEquals(5, $details[0]['feature_map'], 'Feature map should be preserved');
    }

    /**
     * Test that device detail page shows actual capabilities when v3 data is available.
     */
    public function testDevicePageShowsActualCapabilitiesForV3Data(): void
    {
        $client = static::createClient();

        // Submit device with v3 schema
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440054',
            'schema_version' => 3,
            'devices' => [
                [
                    'vendor_id' => 0x3336,
                    'vendor_name' => 'Display V3 Vendor',
                    'product_id' => 0x0054,
                    'product_name' => 'Display V3 Dimmer',
                    'hardware_version' => '1.0',
                    'software_version' => '1.0.0',
                    'endpoints' => [
                        [
                            'endpoint_id' => 1,
                            'device_types' => [['id' => 257, 'revision' => 2]], // Dimmable Light
                            'server_clusters' => [
                                [
                                    'id' => 6,  // OnOff
                                    'feature_map' => 1,  // LT feature
                                    'attribute_list' => [0, 16384, 16385, 65528, 65529, 65531, 65532, 65533],
                                    'accepted_command_list' => [0, 1, 2, 64],  // Off, On, Toggle, OffWithEffect
                                    'generated_command_list' => [],
                                ],
                                [
                                    'id' => 8,  // LevelControl
                                    'feature_map' => 3,  // OO + LT features
                                    'attribute_list' => [0, 1, 2, 15, 17, 65528, 65529, 65531, 65532, 65533],
                                    'accepted_command_list' => [0, 1, 2, 3, 4, 5, 6, 7],
                                    'generated_command_list' => [],
                                ],
                            ],
                            'client_clusters' => [],
                        ],
                    ],
                ],
            ],
        ]));
        $this->assertResponseIsSuccessful();

        // Visit device page
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('a:contains("Display V3 Dimmer")');
        $this->assertGreaterThan(0, $deviceLink->count(), 'Device link should exist');

        $crawler = $client->click($deviceLink->link());
        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();

        // Should show the actual telemetry indicator (checkmark)
        $this->assertStringContainsString('✓', $content, 'Should show actual telemetry indicator');

        // Should show "Actual" label for feature map
        $this->assertStringContainsString('Actual', $content, 'Should show Actual label for v3 data');

        // Should show commands (these come from the actual telemetry)
        $this->assertStringContainsString('Commands', $content, 'Should show Commands section');
    }

    /**
     * Test v3 schema with connectivity cluster details for Thread.
     */
    public function testSubmitV3SchemaWithThreadConnectivity(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440055',
            'schema_version' => 3,
            'devices' => [
                [
                    'vendor_id' => 0x3337,
                    'vendor_name' => 'Thread V3 Vendor',
                    'product_id' => 0x0055,
                    'product_name' => 'Thread V3 Sensor',
                    'hardware_version' => '1.0',
                    'software_version' => '1.0.0',
                    'endpoints' => [
                        [
                            'endpoint_id' => 0,
                            'device_types' => [['id' => 22, 'revision' => 1]],
                            'server_clusters' => [
                                ['id' => 53, 'feature_map' => 15, 'attribute_list' => [0, 1, 2], 'accepted_command_list' => [], 'generated_command_list' => []], // Thread Network Diagnostics
                                ['id' => 29, 'feature_map' => 0, 'attribute_list' => [0, 1, 2, 3], 'accepted_command_list' => [], 'generated_command_list' => []],
                            ],
                            'client_clusters' => [],
                        ],
                    ],
                ],
            ],
        ]));

        $this->assertResponseIsSuccessful();

        // Verify connectivity type was extracted from v3 format
        $container = static::getContainer();
        $connection = $container->get('doctrine.dbal.default_connection');

        $device = $connection->fetchAssociative(
            'SELECT connectivity_types FROM products WHERE vendor_id = ? AND product_id = ?',
            [0x3337, 0x0055]
        );
        $this->assertNotFalse($device);

        $connectivity = json_decode($device['connectivity_types'], true);
        $this->assertContains('thread', $connectivity, 'Thread connectivity should be detected from v3 cluster format');
    }
}
