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

    public function testVendorsIndexShowsFixtureVendors(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/vendors');

        $this->assertResponseIsSuccessful();

        // Check that fixture vendors are displayed
        $this->assertSelectorTextContains('.vendor-list', 'Apple');
        $this->assertSelectorTextContains('.vendor-list', 'Eve Systems');
        $this->assertSelectorTextContains('.vendor-list', 'Philips Hue');
        $this->assertSelectorTextContains('.vendor-list', 'Nanoleaf');

        // Verify vendor count is displayed
        $this->assertSelectorTextContains('.page-header p', '4 vendors');
    }

    public function testVendorShowPageWithFixtureData(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/vendor/eve-systems');

        $this->assertResponseIsSuccessful();

        // Check vendor name in header
        $this->assertSelectorTextContains('.vendor-header h1', 'Eve Systems');

        // Check vendor metadata
        $this->assertSelectorTextContains('.vendor-header .meta', 'Vendor ID: 4874');
        $this->assertSelectorTextContains('.vendor-header .meta', '3 devices');

        // Check that devices are listed
        $this->assertSelectorTextContains('.device-list', 'Eve Motion');
        $this->assertSelectorTextContains('.device-list', 'Eve Energy');
        $this->assertSelectorTextContains('.device-list', 'Eve Door & Window');
    }

    public function testVendorShowPageDisplaysDeviceCount(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/vendor/apple');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.vendor-header h1', 'Apple');
        $this->assertSelectorTextContains('.vendor-header .meta', '2 devices');
    }

    public function testVendorShowNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vendor/non-existent-vendor-slug');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testVendorShowDevicesHaveCorrectLinks(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/vendor/philips-hue');

        $this->assertResponseIsSuccessful();

        // Check that device links exist
        $deviceLinks = $crawler->filter('.device-info h3 a');
        $this->assertGreaterThan(0, $deviceLinks->count());

        // Each device link should point to /device/{id}
        $deviceLinks->each(function ($node) {
            $href = $node->attr('href');
            $this->assertMatchesRegularExpression('/^\/device\/\d+$/', $href);
        });
    }

    public function testVendorIndexLinksToVendorPages(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/vendors');

        $this->assertResponseIsSuccessful();

        // Click on a vendor link
        $link = $crawler->filter('.vendor-info h3 a')->first()->link();
        $client->click($link);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.vendor-header');
    }
}
