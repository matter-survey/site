<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class DeviceControllerTest extends WebTestCase
{
    public function testIndexPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testIndexPageWithSearch(): void
    {
        $client = static::createClient();
        $client->request('GET', '/', ['q' => 'test']);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexPageWithPagination(): void
    {
        $client = static::createClient();
        $client->request('GET', '/', ['page' => '2']);

        $this->assertResponseIsSuccessful();
    }

    public function testDeviceShowNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/device/999999');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDeviceShowInvalidId(): void
    {
        $client = static::createClient();
        $client->request('GET', '/device/invalid');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
