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

        // Check that device count stat is displayed (exact count varies with DCL data)
        $this->assertSelectorTextContains('.stats', 'Known Devices');
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
        $crawler = $client->request('GET', '/', ['q' => 'Signify']);

        $this->assertResponseIsSuccessful();

        // Should find Signify (Philips Hue) devices
        $this->assertSelectorTextContains('.device-list', 'Hue');
    }

    public function testIndexPageSearchNoResults(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/', ['q' => 'NonExistentDeviceName12345']);

        $this->assertResponseIsSuccessful();

        // Should show empty state
        $this->assertSelectorTextContains('.empty-state', 'No devices match your filters');
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

        // Start from vendor page (slug now includes specId)
        $crawler = $client->request('GET', '/vendor/eve-4874');
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

    // ========================================
    // Faceted Search Tests
    // ========================================

    public function testIndexPageHasFilterSidebar(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();

        // Check filter sidebar elements
        $this->assertSelectorExists('.filter-sidebar');
        $this->assertSelectorExists('.filter-section');

        // Check connectivity filters exist
        $this->assertSelectorTextContains('.filter-sidebar', 'Connectivity');

        // Check vendor filters exist
        $this->assertSelectorTextContains('.filter-sidebar', 'Vendor');

        // Check binding filters exist
        $this->assertSelectorTextContains('.filter-sidebar', 'Binding Support');
    }

    public function testIndexPageFilterByConnectivityThread(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/', ['connectivity' => ['thread']]);

        $this->assertResponseIsSuccessful();

        // Should show active filter in results header
        $this->assertSelectorExists('.active-filters');
        $this->assertSelectorTextContains('.active-filter', 'Thread');

        // Thread checkbox should be checked
        $threadCheckbox = $crawler->filter('input[name="connectivity[]"][value="thread"]');
        if ($threadCheckbox->count() > 0) {
            $this->assertEquals('checked', $threadCheckbox->attr('checked'));
        }
    }

    public function testIndexPageFilterByConnectivityWifi(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/', ['connectivity' => ['wifi']]);

        $this->assertResponseIsSuccessful();

        // Should show active filter (capitalized as "Wifi")
        $this->assertSelectorExists('.active-filters');
        $this->assertSelectorTextContains('.active-filter', 'Wifi');
    }

    public function testIndexPageFilterByBindingEnabled(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/', ['binding' => '1']);

        $this->assertResponseIsSuccessful();

        // Should show active filter pill for binding (with capital B)
        $this->assertSelectorExists('.active-filters');
        $this->assertSelectorTextContains('.active-filter', 'With Binding');
    }

    public function testIndexPageFilterByBindingDisabled(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/', ['binding' => '0']);

        $this->assertResponseIsSuccessful();

        // Should show active filter pill for binding (with capital B)
        $this->assertSelectorExists('.active-filters');
        $this->assertSelectorTextContains('.active-filter', 'Without Binding');
    }

    public function testIndexPageClearAllFilters(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/', [
            'connectivity' => ['thread'],
            'binding' => '1',
        ]);

        $this->assertResponseIsSuccessful();

        // Should show clear all link in filter header
        $clearLink = $crawler->filter('.filter-clear');
        $this->assertGreaterThan(0, $clearLink->count(), 'Should have clear all link');

        // Click clear all
        $client->click($clearLink->link());
        $this->assertResponseIsSuccessful();

        // Should redirect to page without filters
        $this->assertStringNotContainsString('connectivity', $client->getRequest()->getUri());
    }

    public function testIndexPageCombinedFilters(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/', [
            'connectivity' => ['thread'],
            'binding' => '1',
            'q' => 'Eve',
        ]);

        $this->assertResponseIsSuccessful();

        // Should show multiple active filters
        $this->assertSelectorExists('.active-filters');
        $activeFilters = $crawler->filter('.active-filter');
        $this->assertGreaterThanOrEqual(2, $activeFilters->count(), 'Should show multiple active filters');
    }

    public function testIndexPagePaginationPreservesFilters(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/', [
            'connectivity' => ['thread'],
            'page' => '1',
        ]);

        $this->assertResponseIsSuccessful();

        // Check if pagination links preserve filter parameters
        $paginationLinks = $crawler->filter('.pagination a');
        if ($paginationLinks->count() > 0) {
            $href = $paginationLinks->first()->attr('href');
            $this->assertStringContainsString('connectivity', $href, 'Pagination should preserve filter parameters');
        }
    }

    public function testIndexPageRemoveIndividualFilter(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/', [
            'connectivity' => ['thread', 'wifi'],
        ]);

        $this->assertResponseIsSuccessful();

        // Find remove link in active filters
        $removeLinks = $crawler->filter('.active-filter a');
        if ($removeLinks->count() > 0) {
            // Remove one filter
            $client->click($removeLinks->first()->link());
            $this->assertResponseIsSuccessful();
        }
    }

    public function testIndexPageMobileFilterToggle(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();

        // Check mobile toggle button exists
        $this->assertSelectorExists('.filter-toggle');
        $this->assertSelectorTextContains('.filter-toggle', 'Filters');
    }

    public function testIndexPageFacetCounts(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();

        // Check that facet counts are displayed (using .count class)
        $facetCounts = $crawler->filter('.filter-option .count');
        $this->assertGreaterThan(0, $facetCounts->count(), 'Should show facet counts');
    }

    // ========================================
    // Structured Data Tests
    // ========================================

    public function testIndexPageHasOpenGraphMetaTags(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();

        // Check OpenGraph meta tags
        $this->assertSelectorExists('meta[property="og:site_name"]');
        $this->assertSelectorExists('meta[property="og:title"]');
        $this->assertSelectorExists('meta[property="og:description"]');
        $this->assertSelectorExists('meta[property="og:type"]');

        // Check Twitter Card meta tags
        $this->assertSelectorExists('meta[name="twitter:card"]');
        $this->assertSelectorExists('meta[name="twitter:title"]');
        $this->assertSelectorExists('meta[name="twitter:description"]');
    }

    public function testIndexPageHasWebSiteJsonLd(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();

        // Check JSON-LD script exists
        $jsonLdScripts = $crawler->filter('script[type="application/ld+json"]');
        $this->assertGreaterThan(0, $jsonLdScripts->count(), 'Page should have JSON-LD structured data');

        // Parse and validate first JSON-LD block (WebSite)
        $jsonLd = json_decode($jsonLdScripts->first()->text(), true);
        $this->assertNotNull($jsonLd, 'JSON-LD should be valid JSON');
        $this->assertEquals('https://schema.org', $jsonLd['@context']);
        $this->assertEquals('WebSite', $jsonLd['@type']);
        $this->assertEquals('Matter Survey', $jsonLd['name']);
        $this->assertArrayHasKey('potentialAction', $jsonLd);
        $this->assertEquals('SearchAction', $jsonLd['potentialAction']['@type']);
    }

    public function testDeviceShowPageHasProductJsonLd(): void
    {
        $client = static::createClient();

        // Get device from index
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $crawler = $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();

        // Check JSON-LD script exists
        $jsonLdScripts = $crawler->filter('script[type="application/ld+json"]');
        $this->assertGreaterThan(0, $jsonLdScripts->count(), 'Device page should have JSON-LD');

        // Parse and validate JSON-LD
        $jsonLd = json_decode($jsonLdScripts->first()->text(), true);
        $this->assertNotNull($jsonLd, 'JSON-LD should be valid JSON');
        $this->assertEquals('https://schema.org', $jsonLd['@context']);
        $this->assertEquals('Product', $jsonLd['@type']);
        $this->assertArrayHasKey('name', $jsonLd);
        $this->assertArrayHasKey('manufacturer', $jsonLd);
        $this->assertEquals('Organization', $jsonLd['manufacturer']['@type']);
    }

    public function testDeviceShowPageHasProductOpenGraph(): void
    {
        $client = static::createClient();

        // Get device from index
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $crawler = $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();

        // Check OpenGraph type is product
        $ogType = $crawler->filter('meta[property="og:type"]')->attr('content');
        $this->assertEquals('product', $ogType);
    }
}
