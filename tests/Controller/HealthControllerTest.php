<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HealthControllerTest extends WebTestCase
{
    public function testHealthEndpointReturnsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $response = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('status', $response);
        $this->assertArrayHasKey('checks', $response);
        $this->assertArrayHasKey('timestamp', $response);
        $this->assertEquals('healthy', $response['status']);
        $this->assertArrayHasKey('database', $response['checks']);
    }
}
