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

    /**
     * Test that device compatibility section shows when device has client clusters.
     *
     * Eve Motion has On/Off client cluster (6), which means it can control devices
     * that have On/Off server cluster (like Eve Energy, Hue bulbs).
     */
    public function testDeviceShowPageDisplaysCompatibilityForClientClusters(): void
    {
        $client = static::createClient();

        // Find Eve Motion device (has On/Off client cluster)
        $crawler = $client->request('GET', '/', ['q' => 'Eve Motion']);
        $this->assertResponseIsSuccessful();

        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $crawler = $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.device-header', 'Eve Motion');

        // Should have compatibility section because Eve Motion has On/Off client cluster
        $this->assertSelectorExists('.compatibility-section');
        $this->assertSelectorTextContains('.compatibility-section h3', 'Device Compatibility');

        // Should show On/Off cluster compatibility (Eve Motion can control On/Off devices)
        $this->assertSelectorTextContains('.compatibility-section', 'On/Off');

        // Should list compatible devices (Eve Energy and Hue bulb have On/Off server)
        $compatibleDevices = $crawler->filter('.compatible-device');
        $this->assertGreaterThan(0, $compatibleDevices->count(), 'Should list compatible devices');
    }

    /**
     * Test that devices without client clusters don't show compatibility section.
     *
     * Eve Door & Window only has server clusters, no client clusters,
     * so it shouldn't show the "can communicate with" section.
     */
    public function testDeviceShowPageHidesCompatibilityWhenNoClientClusters(): void
    {
        $client = static::createClient();

        // Find Eve Door & Window (has no client clusters)
        $crawler = $client->request('GET', '/', ['q' => 'Eve Door']);
        $this->assertResponseIsSuccessful();

        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $crawler = $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.device-header', 'Eve Door');

        // Should NOT have compatibility section (no client clusters)
        $this->assertSelectorNotExists('.compatibility-section');
    }

    /**
     * Test that compatible device links work correctly.
     */
    public function testDeviceCompatibilityLinksWork(): void
    {
        $client = static::createClient();

        // Find Eve Motion (has compatibility)
        $crawler = $client->request('GET', '/', ['q' => 'Eve Motion']);
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $crawler = $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();

        // Click on a compatible device link
        $compatibleDeviceLink = $crawler->filter('.compatible-device')->first();
        if ($compatibleDeviceLink->count() > 0) {
            $client->click($compatibleDeviceLink->link());
            $this->assertResponseIsSuccessful();
            $this->assertSelectorExists('.device-header');
        }
    }

    /**
     * Test that device shows "Can provide data to" section for devices with server clusters.
     *
     * Eve Energy has On/Off server cluster, and Eve Motion has On/Off client cluster,
     * so Eve Energy should show it can provide On/Off data to Eve Motion.
     */
    public function testDeviceShowPageDisplaysCanProvideToForServerClusters(): void
    {
        $client = static::createClient();

        // Find Eve Energy (has On/Off server cluster)
        $crawler = $client->request('GET', '/', ['q' => 'Eve Energy']);
        $this->assertResponseIsSuccessful();

        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $crawler = $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.device-header', 'Eve Energy');

        // Should have compatibility section because Eve Energy has server clusters
        // that match other devices' client clusters (On/Off → Eve Motion)
        $this->assertSelectorExists('.compatibility-section');
        $this->assertSelectorTextContains('.compatibility-section', 'Can provide data to');
    }

    /**
     * Test that Hue bulb shows "Can provide data to" section.
     *
     * Hue White and Color Ambiance has On/Off, Level Control, Color Control server clusters.
     * Eve Motion has On/Off client cluster, so Hue should show it can provide data to it.
     */
    public function testDeviceShowPageDisplaysCanProvideToForHueBulb(): void
    {
        $client = static::createClient();

        // Find Hue bulb
        $crawler = $client->request('GET', '/', ['q' => 'Hue White and Color']);
        $this->assertResponseIsSuccessful();

        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $crawler = $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.device-header', 'Hue');

        // Should have compatibility section with "Can provide data to"
        $this->assertSelectorExists('.compatibility-section');
        $this->assertSelectorTextContains('.compatibility-section', 'Can provide data to');
        $this->assertSelectorTextContains('.compatibility-section', 'On/Off');
    }
}
