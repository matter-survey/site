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
}
