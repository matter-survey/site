<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class VendorControllerTest extends WebTestCase
{
    public function testVendorsIndexPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vendors');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
        $this->assertSelectorTextContains('.page-header h1', 'Vendors');
    }

    public function testVendorShowNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vendor/non-existent-vendor-slug');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testVendorShowWithPagination(): void
    {
        $client = static::createClient();
        // Even if vendor doesn't exist, pagination param should still result in 404
        $client->request('GET', '/vendor/non-existent-vendor?page=2');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
