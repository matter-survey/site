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
                            'device_types' => [0x0100],
                            'clusters' => [0x0006, 0x0008],
                            'has_binding_cluster' => true,
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
}
