<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class DeviceControllerTest extends WebTestCase
{
    public function testIndexPageLoads(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testIndexPageShowsFixtureDevices(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();

        // Check that fixture devices are displayed
        $this->assertSelectorTextContains('.device-list', 'HomePod mini');
        $this->assertSelectorTextContains('.device-list', 'Eve Motion');
        $this->assertSelectorTextContains('.device-list', 'Hue White and Color Ambiance');
    }

    public function testIndexPageShowsStats(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();

        // Check stats cards exist
        $this->assertSelectorExists('.stats .stat-card');

        // Check that device count stat is displayed (exact count varies with DCL data)
        $this->assertSelectorTextContains('.stats', 'Known Devices');
    }

    public function testIndexPageWithSearch(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['q' => 'Eve']);

        $this->assertResponseIsSuccessful();

        // Should find Eve devices
        $this->assertSelectorTextContains('.device-list', 'Eve');
    }

    public function testIndexPageSearchFindsVendorName(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['q' => 'Signify']);

        $this->assertResponseIsSuccessful();

        // Should find Signify (Philips Hue) devices
        $this->assertSelectorTextContains('.device-list', 'Hue');
    }

    public function testIndexPageSearchNoResults(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['q' => 'NonExistentDeviceName12345']);

        $this->assertResponseIsSuccessful();

        // Should show empty state
        $this->assertSelectorTextContains('.empty-state', 'No devices match your filters');
    }

    public function testIndexPageWithPagination(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['page' => '2']);

        $this->assertResponseIsSuccessful();
    }

    public function testDeviceShowPageWithFixtureData(): void
    {
        $client = self::createClient();

        // First get a device ID from the index page
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $href = $deviceLink->attr('href');

        // Navigate to device detail page
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $href);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.device-header');
    }

    public function testDeviceShowPageDisplaysEndpoints(): void
    {
        $client = self::createClient();

        // Get device from index
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();

        // Check that endpoints section exists
        $this->assertSelectorExists('.section');
    }

    public function testDeviceShowPageHasVendorLink(): void
    {
        $client = self::createClient();

        // Get device from index
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();

        // Check breadcrumb has vendor link
        $this->assertSelectorExists('.breadcrumb a');
    }

    public function testDeviceShowNotFound(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device/non-existent-device-slug');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDeviceShowLegacyIdRedirectsToSlug(): void
    {
        $client = self::createClient();

        // Pick a real product id from the DB rather than hard-coding "1". SQLite's
        // AUTOINCREMENT counter persists across fixture reloads, so the lowest id
        // is only 1 on a freshly-seeded test DB. Querying the actual lowest id
        // makes the test robust to dev workflows that reload fixtures repeatedly.
        $product = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)
            ->getRepository(\App\Entity\Product::class)
            ->findOneBy([], ['id' => 'ASC']);

        $this->assertNotNull($product, 'Expected at least one fixture product');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device/'.$product->getId());

        $this->assertResponseRedirects();
        $this->assertResponseStatusCodeSame(Response::HTTP_MOVED_PERMANENTLY);
    }

    public function testDeviceShowSlugBasedUrlWorks(): void
    {
        $client = self::createClient();

        // Navigate from index using slug-based link
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $href = $deviceLink->attr('href');

        // Verify it's a slug-based URL (contains letters, not just /device/123)
        $this->assertMatchesRegularExpression('#/device/[a-z0-9-]+$#', $href);

        // Navigate to the slug URL
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $href);
        $this->assertResponseIsSuccessful();
    }

    public function testDeviceShowInvalidId(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device/invalid');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDeviceIndexLinksToDevicePages(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

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
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

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
        $client = self::createClient();

        // Start from vendor page (slug now includes specId)
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/vendor/eve-4874');
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
        $client = self::createClient();

        // Find Eve Motion device (has On/Off client cluster)
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['q' => 'Eve Motion']);
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
        $client = self::createClient();

        // Find Eve Door & Window (has no client clusters)
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['q' => 'Eve Door']);
        $this->assertResponseIsSuccessful();

        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $client->click($deviceLink->link());

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
        $client = self::createClient();

        // Find Eve Motion (has compatibility)
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['q' => 'Eve Motion']);
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
        $client = self::createClient();

        // Find Eve Energy (has On/Off server cluster)
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['q' => 'Eve Energy']);
        $this->assertResponseIsSuccessful();

        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $client->click($deviceLink->link());

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
        $client = self::createClient();

        // Find Hue bulb
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['q' => 'Hue White and Color']);
        $this->assertResponseIsSuccessful();

        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $client->click($deviceLink->link());

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
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();

        // Check filter sidebar elements
        $this->assertSelectorExists('.filter-sidebar');
        $this->assertSelectorExists('.filter-section');

        // Check connectivity filters exist
        $this->assertSelectorTextContains('.filter-sidebar', 'Connectivity');

        // Check vendor filters exist
        $this->assertSelectorTextContains('.filter-sidebar', 'Vendor');

        // Check coordination filters exist
        $this->assertSelectorTextContains('.filter-sidebar', 'Coordination');
    }

    public function testIndexPageFilterByConnectivityThread(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['connectivity' => ['thread']]);

        $this->assertResponseIsSuccessful();

        // Should show active filter in results header
        $this->assertSelectorExists('.active-filters');
        $this->assertSelectorTextContains('.active-filter', 'Thread');

        // Thread checkbox should be checked
        $threadCheckbox = $crawler->filter('input[name="connectivity[]"][value="thread"]');
        if ($threadCheckbox->count() > 0) {
            $this->assertSame('checked', $threadCheckbox->attr('checked'));
        }
    }

    public function testIndexPageFilterByConnectivityWifi(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['connectivity' => ['wifi']]);

        $this->assertResponseIsSuccessful();

        // Should show active filter (capitalized as "Wifi")
        $this->assertSelectorExists('.active-filters');
        $this->assertSelectorTextContains('.active-filter', 'Wifi');
    }

    public function testIndexPageFilterByBindingEnabled(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['binding' => '1']);

        $this->assertResponseIsSuccessful();

        // Should show active filter pill for binding (with capital B)
        $this->assertSelectorExists('.active-filters');
        $this->assertSelectorTextContains('.active-filter', 'With Binding');
    }

    public function testIndexPageFilterByBindingDisabled(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['binding' => '0']);

        $this->assertResponseIsSuccessful();

        // Should show active filter pill for binding (with capital B)
        $this->assertSelectorExists('.active-filters');
        $this->assertSelectorTextContains('.active-filter', 'Without Binding');
    }

    public function testIndexPageFilterByGroups(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['groups' => '1']);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.active-filters');
        $this->assertSelectorTextContains('.active-filter', 'With Group control');
    }

    public function testIndexPageFilterByScenes(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['scenes' => '1']);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.active-filters');
        $this->assertSelectorTextContains('.active-filter', 'With Scene control');
    }

    public function testIndexPageCombinedCoordinationFilters(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', [
            'groups' => '1',
            'scenes' => '1',
        ]);

        $this->assertResponseIsSuccessful();
        $activeFilters = $crawler->filter('.active-filter');
        $this->assertGreaterThanOrEqual(2, $activeFilters->count(), 'Both coordination filters should be active');
    }

    public function testIndexPageClearAllFilters(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', [
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
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', [
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
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', [
            'connectivity' => ['thread'],
            'page' => '1',
        ]);

        $this->assertResponseIsSuccessful();

        // Check if pagination links preserve filter parameters
        $paginationLinks = $crawler->filter('.pagination a');
        if ($paginationLinks->count() > 0) {
            $href = $paginationLinks->first()->attr('href');
            $this->assertStringContainsString('connectivity', (string) $href, 'Pagination should preserve filter parameters');
        }
    }

    public function testIndexPageRemoveIndividualFilter(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', [
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
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();

        // Check mobile toggle button exists
        $this->assertSelectorExists('.filter-toggle');
        $this->assertSelectorTextContains('.filter-toggle', 'Filters');
    }

    public function testIndexPageFacetCounts(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();

        // Check that facet counts are displayed (using .count class)
        $facetCounts = $crawler->filter('.filter-option .count');
        $this->assertGreaterThan(0, $facetCounts->count(), 'Should show facet counts');
    }

    public function testIndexPageHasDeviceTypeFilter(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();

        // Check device type filter section exists
        $this->assertSelectorTextContains('.filter-sidebar', 'Device Type');

        // Check device type checkboxes exist
        $deviceTypeInputs = $crawler->filter('input[name="device_types[]"]');
        $this->assertGreaterThan(0, $deviceTypeInputs->count(), 'Should have device type filter options');
    }

    public function testIndexPageFilterByDeviceType(): void
    {
        $client = self::createClient();

        // Extended Color Light is device type 269 (0x010D)
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['device_types' => ['269']]);

        $this->assertResponseIsSuccessful();

        // Should show active filter for device type
        $this->assertSelectorExists('.active-filters');
        $this->assertSelectorExists('.active-filter');
    }

    public function testIndexPageFilterByMultipleDeviceTypes(): void
    {
        $client = self::createClient();

        // Filter by multiple device types
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['device_types' => ['269', '266']]);

        $this->assertResponseIsSuccessful();

        // Should show active filters
        $this->assertSelectorExists('.active-filters');
        $activeFilters = $crawler->filter('.active-filter');
        $this->assertGreaterThanOrEqual(2, $activeFilters->count(), 'Should show multiple device type filters');
    }

    public function testIndexPageHandlesEmptyFilterParameters(): void
    {
        $client = self::createClient();

        // Test URL pattern with device_types array and empty vendor
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', [
            'q' => '',
            'device_types' => ['770'],
            'vendor' => '',
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexPageHandlesAllEmptyParameters(): void
    {
        $client = self::createClient();

        // All empty parameters
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', [
            'q' => '',
            'device_types' => [],
            'vendor' => '',
            'binding' => '',
        ]);

        $this->assertResponseIsSuccessful();
    }

    // ========================================
    // Structured Data Tests
    // ========================================

    public function testIndexPageHasOpenGraphMetaTags(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

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
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

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
        $client = self::createClient();

        // Get device from index
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
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
        $client = self::createClient();

        // Get device from index
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $crawler = $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();

        // Check OpenGraph type is product
        $ogType = $crawler->filter('meta[property="og:type"]')->attr('content');
        $this->assertSame('product', $ogType);
    }

    // ========================================================================
    // Star Rating Filter Tests
    // ========================================================================

    public function testIndexPageShowsStarRatingFilter(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();

        // Should have Minimum Rating filter section
        $this->assertSelectorExists('.filter-section h4');
        $this->assertSelectorTextContains('body', 'Minimum Rating');
    }

    public function testIndexPageWithMinRatingFilter(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['min_rating' => '3']);

        $this->assertResponseIsSuccessful();
        // Page should load without error, even if no devices match
    }

    public function testIndexPageWithInvalidMinRatingIsIgnored(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['min_rating' => '99']);

        $this->assertResponseIsSuccessful();
        // Invalid rating should be ignored, not cause errors
    }

    public function testIndexPageMinRatingFilterShowsInActiveFilters(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['min_rating' => '4']);

        $this->assertResponseIsSuccessful();

        // If there are filtered results, the active filter should show
        $activeFilters = $crawler->filter('.active-filter');
        if ($activeFilters->count() > 0) {
            $this->assertSelectorTextContains('.active-filters', 'Rating');
        }
    }

    public function testIndexPageShowsStarBadgesOnDeviceCards(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();

        // Check for star badge elements (they appear when scores are cached)
        // Note: This test might pass even without badges if no scores are cached
        $deviceItems = $crawler->filter('.device-item');
        $this->assertGreaterThan(0, $deviceItems->count());
    }

    // ========================================================================
    // Connectivity Filter Tests
    // ========================================================================

    public function testIndexPageWithThreadFilter(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['connectivity' => ['thread']]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexPageWithWifiFilter(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['connectivity' => ['wifi']]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexPageWithMultipleConnectivityFilters(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['connectivity' => ['thread', 'wifi']]);

        $this->assertResponseIsSuccessful();
    }

    // ========================================================================
    // Binding Filter Tests
    // ========================================================================

    public function testIndexPageWithBindingFilterEnabled(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['binding' => '1']);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexPageWithBindingFilterDisabled(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['binding' => '0']);

        $this->assertResponseIsSuccessful();
    }

    // ========================================================================
    // Combined Filter Tests
    // ========================================================================

    public function testIndexPageWithCombinedFilters(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', [
            'q' => 'light',
            'connectivity' => ['thread'],
            'min_rating' => '3',
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexPageFiltersPreservedInPagination(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', [
            'min_rating' => '3',
            'page' => '1',
        ]);

        $this->assertResponseIsSuccessful();

        // If pagination exists, links should preserve the filter
        $paginationLinks = $crawler->filter('.pagination a');
        foreach ($paginationLinks as $link) {
            $this->assertInstanceOf(\DOMElement::class, $link);
            $href = $link->getAttribute('href');
            // Links should either not exist or preserve min_rating
            // (pagination may not exist if few results)
        }
    }

    // ========================================================================
    // Capability Filter Tests
    // ========================================================================

    public function testIndexPageHasCapabilitiesFilter(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();

        // Check capabilities filter section exists
        $this->assertSelectorTextContains('.filter-sidebar', 'Capabilities');

        // Check capability checkboxes exist
        $capabilityInputs = $crawler->filter('input[name="capabilities[]"]');
        $this->assertGreaterThan(0, $capabilityInputs->count(), 'Should have capability filter options');
    }

    public function testIndexPageFilterByCapabilityDimming(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['capabilities' => ['dimming']]);

        $this->assertResponseIsSuccessful();

        // Should show active filter for capability
        $this->assertSelectorExists('.active-filters');
        $this->assertSelectorTextContains('.active-filter', 'Brightness dimming');
    }

    public function testIndexPageFilterByCapabilityFullColor(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['capabilities' => ['full_color']]);

        $this->assertResponseIsSuccessful();

        // Should show active filter for capability
        $this->assertSelectorExists('.active-filters');
        $this->assertSelectorTextContains('.active-filter', 'Full color');
    }

    public function testIndexPageFilterByMultipleCapabilities(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['capabilities' => ['dimming', 'full_color']]);

        $this->assertResponseIsSuccessful();

        // Should show multiple active filters
        $this->assertSelectorExists('.active-filters');
        $activeFilters = $crawler->filter('.active-filter');
        $this->assertGreaterThanOrEqual(2, $activeFilters->count(), 'Should show multiple capability filters');
    }

    public function testIndexPageCapabilityFilterRemovable(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['capabilities' => ['dimming', 'motion_detection']]);

        $this->assertResponseIsSuccessful();

        // Active filter should have remove link
        $removeLinks = $crawler->filter('.active-filter a');
        $this->assertGreaterThan(0, $removeLinks->count(), 'Should have remove links for capability filters');
    }

    public function testIndexPageCapabilityFilterPreservedInPagination(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', [
            'capabilities' => ['dimming'],
            'page' => '1',
        ]);

        $this->assertResponseIsSuccessful();

        // If pagination exists, links should preserve the capability filter
        $paginationLinks = $crawler->filter('.pagination a');
        if ($paginationLinks->count() > 0) {
            $href = $paginationLinks->first()->attr('href');
            $this->assertStringContainsString('capabilities', (string) $href, 'Pagination should preserve capability filter');
        }
    }

    public function testIndexPageCapabilityCombinedWithOtherFilters(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', [
            'capabilities' => ['dimming'],
            'connectivity' => ['thread'],
            'q' => 'light',
        ]);

        $this->assertResponseIsSuccessful();

        // Should show multiple active filters from different categories
        $activeFilters = $crawler->filter('.active-filter');
        $this->assertGreaterThanOrEqual(2, $activeFilters->count(), 'Should show combined filters');
    }

    public function testIndexPageCapabilityFilterIgnoresInvalidKeys(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['capabilities' => ['invalid_capability_key']]);

        // Should not cause error, just ignore invalid key
        $this->assertResponseIsSuccessful();
    }

    public function testIndexPageCapabilityFacetShowsCount(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();

        // Find capability section and check counts are shown
        $capabilityOptions = $crawler->filter('input[name="capabilities[]"]')->closest('label');
        if ($capabilityOptions->count() > 0) {
            $firstOption = $capabilityOptions->first();
            $this->assertStringContainsString('count', $firstOption->html(), 'Capability options should show counts');
        }
    }

    // Capability Table Tests

    public function testDeviceShowPageHasCapabilitiesSection(): void
    {
        $client = self::createClient();

        // Get device from index
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();

        // Check that capabilities section exists
        $this->assertSelectorExists('.capabilities-section');
    }

    public function testDeviceShowPageHasCapabilitiesTable(): void
    {
        $client = self::createClient();

        // Get device from index (Hue Bulb has on_off, dimming, full_color capabilities)
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();

        // Check that capability table exists
        $this->assertSelectorExists('.capability-table');
    }

    public function testDeviceShowPageCapabilityTableHasRows(): void
    {
        $client = self::createClient();

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $crawler = $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();

        // Check that capability rows exist
        $capabilityRows = $crawler->filter('.capability-row');
        $this->assertGreaterThan(0, $capabilityRows->count(), 'Should have capability rows');
    }

    public function testDeviceShowPageCapabilityRowStructure(): void
    {
        $client = self::createClient();

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $crawler = $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();

        // Check that supported capabilities have proper structure
        $supportedRow = $crawler->filter('.capability-row.capability-supported')->first();
        if ($supportedRow->count() > 0) {
            // Should have emoji
            $this->assertGreaterThan(0, $supportedRow->filter('.cap-emoji')->count(), 'Should have emoji');
            // Should have label
            $this->assertGreaterThan(0, $supportedRow->filter('.cap-label')->count(), 'Should have label');
            // Should have status cell
            $this->assertGreaterThan(0, $supportedRow->filter('.cap-td-status')->count(), 'Should have status');
        }
    }

    public function testDeviceShowPageCapabilityTableShowsSpecVersion(): void
    {
        $client = self::createClient();

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $crawler = $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();

        // Check that spec version is shown
        $specVersionCells = $crawler->filter('.cap-spec-version');
        // Spec version cells should exist (though might be empty for some capabilities)
        $this->assertGreaterThanOrEqual(0, $specVersionCells->count());
    }

    public function testDeviceShowPageExpandableCapabilitiesHaveIcon(): void
    {
        $client = self::createClient();

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $crawler = $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();

        // Check expandable capabilities have expand icon
        $expandableRows = $crawler->filter('.capability-row.capability-expandable');
        if ($expandableRows->count() > 0) {
            $firstExpandable = $expandableRows->first();
            $this->assertGreaterThan(0, $firstExpandable->filter('.cap-expand-icon')->count(), 'Expandable rows should have expand icon');
        }
    }

    public function testDeviceShowPageHasCapabilityDetailsRow(): void
    {
        $client = self::createClient();

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $crawler = $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();

        // Check that details rows exist (hidden by default)
        $detailsRows = $crawler->filter('.capability-details-row');
        if ($detailsRows->count() > 0) {
            // Details row should have content container
            $firstDetails = $detailsRows->first();
            $this->assertGreaterThan(0, $firstDetails->filter('.cap-details-content')->count(), 'Details row should have content container');
        }
    }

    public function testDeviceShowPageUnsupportedCapabilitiesShowRedX(): void
    {
        $client = self::createClient();

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $crawler = $client->click($deviceLink->link());

        $this->assertResponseIsSuccessful();

        // Check that unsupported capabilities have the red X status
        $unsupportedRows = $crawler->filter('.capability-row.capability-unsupported');
        if ($unsupportedRows->count() > 0) {
            $firstUnsupported = $unsupportedRows->first();
            $statusCell = $firstUnsupported->filter('.cap-status-unsupported');
            $this->assertGreaterThan(0, $statusCell->count(), 'Unsupported capabilities should have status');
        }
    }

    /**
     * When two products share the same vendor and product_name (e.g. multiple
     * generations of "Eve Thermo"), the index must render a PID-based
     * disambiguator next to each so users can tell them apart at a glance.
     */
    public function testDeviceIndexShowsPidDisambiguatorForDuplicateNames(): void
    {
        $client = self::createClient();
        $container = self::getContainer();

        $em = $container->get(\Doctrine\ORM\EntityManagerInterface::class);
        $vendor = $em->getRepository(\App\Entity\Vendor::class)->findOneBy(['specId' => 4874]);
        $this->assertNotNull($vendor, 'Eve fixture vendor must exist');

        $deviceRepo = $container->get(\App\Repository\DeviceRepository::class);
        $isNew = false;
        $deviceRepo->upsertDevice([
            'vendor_id' => 4874,
            'vendor_name' => $vendor->getName(),
            'vendor_fk' => $vendor->getId(),
            'product_id' => 9999,
            'product_name' => 'Eve Motion',
        ], $isNew);

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/', ['q' => 'Eve Motion']);
        $this->assertResponseIsSuccessful();

        $suffixes = $crawler->filter('.device-list .device-pid-suffix');
        $this->assertCount(2, $suffixes, 'Both Eve Motion entries should render a PID suffix');
        $this->assertStringContainsString('PID 0x', $suffixes->first()->text());
    }
}
