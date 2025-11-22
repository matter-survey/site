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

    public function testIndexPageShowsFixtureDevices(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();

        // Check that fixture devices are displayed
        $this->assertSelectorTextContains('.device-list', 'HomePod mini');
        $this->assertSelectorTextContains('.device-list', 'Eve Motion');
        $this->assertSelectorTextContains('.device-list', 'Hue White and Color Ambiance');
    }

    public function testIndexPageShowsStats(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();

        // Check stats cards exist
        $this->assertSelectorExists('.stats .stat-card');

        // Check that we have the expected number of devices (8 from fixtures)
        $this->assertSelectorTextContains('.stats', '8');
    }

    public function testIndexPageWithSearch(): void
    {
        $client = static::createClient();
        $client->request('GET', '/', ['q' => 'Eve']);

        $this->assertResponseIsSuccessful();

        // Should find Eve devices
        $this->assertSelectorTextContains('.device-list', 'Eve');
    }

    public function testIndexPageSearchFindsVendorName(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/', ['q' => 'Philips']);

        $this->assertResponseIsSuccessful();

        // Should find Philips Hue devices
        $this->assertSelectorTextContains('.device-list', 'Hue');
    }

    public function testIndexPageSearchNoResults(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/', ['q' => 'NonExistentDeviceName12345']);

        $this->assertResponseIsSuccessful();

        // Should show empty state
        $this->assertSelectorTextContains('.empty-state', 'No devices found');
    }

    public function testIndexPageWithPagination(): void
    {
        $client = static::createClient();
        $client->request('GET', '/', ['page' => '2']);

        $this->assertResponseIsSuccessful();
    }

    public function testDeviceShowPageWithFixtureData(): void
    {
        $client = static::createClient();

        // First get a device ID from the index page
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $href = $deviceLink->attr('href');

        // Navigate to device detail page
        $client->request('GET', $href);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.device-header');
    }

    public function testDeviceShowPageDisplaysEndpoints(): void
    {
        $client = static::createClient();

        // Get device from index
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();

        // Check that endpoints section exists
        $this->assertSelectorExists('.section');
    }

    public function testDeviceShowPageHasVendorLink(): void
    {
        $client = static::createClient();

        // Get device from index
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $crawler = $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();

        // Check breadcrumb has vendor link
        $this->assertSelectorExists('.breadcrumb a');
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

    public function testDeviceIndexLinksToDevicePages(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();

        // All device links should be valid
        $deviceLinks = $crawler->filter('.device-info h3 a');
        $this->assertGreaterThan(0, $deviceLinks->count());

        // Click first device link
        $link = $deviceLinks->first()->link();
        $client->click($link);

        $this->assertResponseIsSuccessful();
    }

    public function testDeviceIndexShowsVendorLinks(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();

        // Check that vendor links exist in device meta
        $vendorLinks = $crawler->filter('.device-meta a');
        $this->assertGreaterThan(0, $vendorLinks->count());

        // First vendor link should go to vendor page
        $href = $vendorLinks->first()->attr('href');
        $this->assertMatchesRegularExpression('/^\/vendor\/[\w-]+$/', $href);
    }

    public function testDeviceShowPageFromVendorPage(): void
    {
        $client = static::createClient();

        // Start from vendor page
        $crawler = $client->request('GET', '/vendor/eve-systems');
        $this->assertResponseIsSuccessful();

        // Click on a device
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.device-header', 'Eve');
    }
}
