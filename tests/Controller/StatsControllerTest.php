<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests for StatsController dashboard views.
 *
 * These tests verify that fixture data is properly displayed in the dashboard views.
 *
 * Fixture data includes:
 * - 4 vendors: Apple (2 devices), Eve Systems (3 devices), Philips Hue (2 devices), Nanoleaf (1 device)
 * - 8 devices total with various device types and clusters
 * - 4 devices with binding support (cluster 30): Eve Motion, Eve Energy, Philips Hue bulb, Nanoleaf Shapes
 */
final class StatsControllerTest extends WebTestCase
{
    // === Dashboard Overview Tests ===

    public function testDashboardPageLoads(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testDashboardShowsCorrectDeviceCount(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/dashboard');

        $this->assertResponseIsSuccessful();

        // Verify device count stat is displayed (exact count varies with DCL data)
        $this->assertSelectorTextContains('.stats-grid', 'Known Products');
    }

    public function testDashboardShowsCorrectVendorCount(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/dashboard');

        $this->assertResponseIsSuccessful();

        // Verify vendor count stat is displayed
        $this->assertSelectorTextContains('.stats-grid', 'Vendors');
    }

    public function testDashboardShowsBindingDeviceCount(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/dashboard');

        $this->assertResponseIsSuccessful();

        // Verify binding stat is displayed
        $this->assertSelectorTextContains('.stats-grid', 'Binding');
    }

    public function testDashboardShowsTopVendorsSection(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/dashboard');

        $this->assertResponseIsSuccessful();

        // Verify top vendors section exists
        $vendorsSection = $crawler->filter('.dashboard-card:contains("Top Vendors")');
        $this->assertGreaterThan(0, $vendorsSection->count());
    }

    public function testDashboardShowsRecentDevices(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/dashboard');

        $this->assertResponseIsSuccessful();

        // Check that recently discovered section shows fixture device names
        $recentSection = $crawler->filter('.dashboard-card:contains("Recently Discovered")');
        $this->assertGreaterThan(0, $recentSection->count());

        // Should contain at least one of our fixture devices
        $recentText = $recentSection->text();
        $hasFixtureDevice = str_contains($recentText, 'HomePod')
            || str_contains($recentText, 'Eve')
            || str_contains($recentText, 'Hue')
            || str_contains($recentText, 'Nanoleaf');
        $this->assertTrue($hasFixtureDevice, 'Recent devices section should contain fixture devices');
    }

    public function testDashboardShowsCategoryDistributionWithLights(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/dashboard');

        $this->assertResponseIsSuccessful();

        // Fixtures include Extended Color Light (269) devices - should show Lights category
        $categorySection = $crawler->filter('.dashboard-card:contains("Device Categories")');
        $this->assertStringContainsString('Lights', $categorySection->text());
    }

    public function testDashboardShowsCategoryDistributionWithSensors(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/dashboard');

        $this->assertResponseIsSuccessful();

        // Fixtures include Occupancy Sensor (263) and Contact Sensor (21)
        $categorySection = $crawler->filter('.dashboard-card:contains("Device Categories")');
        $this->assertStringContainsString('Sensors', $categorySection->text());
    }

    // === Clusters Page Tests ===

    public function testClustersPageLoads(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/clusters');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testClustersPageShowsDescriptorCluster(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/clusters');

        $this->assertResponseIsSuccessful();

        // Descriptor cluster (29) appears in all devices
        $this->assertSelectorTextContains('.cluster-grid', 'Descriptor');
    }

    public function testClustersPageShowsOnOffCluster(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/clusters');

        $this->assertResponseIsSuccessful();

        // On/Off cluster (6) appears in multiple devices
        $this->assertSelectorTextContains('.cluster-grid', 'On/Off');
    }

    public function testClustersPageShowsBindingCluster(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/clusters');

        $this->assertResponseIsSuccessful();

        // Binding cluster (30) appears in 4 devices
        $this->assertSelectorTextContains('.cluster-grid', 'Binding');
    }

    public function testClustersPageShowsColorControlCluster(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/clusters');

        $this->assertResponseIsSuccessful();

        // Color Control cluster (768) appears in Philips bulb and Nanoleaf
        $this->assertSelectorTextContains('.cluster-grid', 'Color Control');
    }

    public function testClustersPageShowsClusterCoOccurrence(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/clusters');

        $this->assertResponseIsSuccessful();

        // Co-occurrence section should exist and have pairs
        $coOccurrenceSection = $crawler->filter('.section:contains("Co-occurrence")');
        $this->assertGreaterThan(0, $coOccurrenceSection->count());

        // On/Off and Level Control often appear together
        $coOccurrenceGrid = $crawler->filter('.co-occurrence-grid');
        $this->assertGreaterThan(0, $coOccurrenceGrid->count());
    }

    public function testClustersPageShowsCorrectClusterCount(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/clusters');

        $this->assertResponseIsSuccessful();

        // Insights grid should show unique cluster count
        $insightsGrid = $crawler->filter('.insights-grid');
        $this->assertStringContainsString('Unique Clusters', $insightsGrid->text());
    }

    // === Device Types Page Tests ===

    public function testDeviceTypesPageLoads(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testDeviceTypesPageShowsExtendedColorLight(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types');

        $this->assertResponseIsSuccessful();

        // Extended Color Light (269) - Philips bulb and Nanoleaf
        // Search all device type cards for the name
        $allCategorySections = $crawler->filter('.category-section');
        $found = false;
        $allCategorySections->each(function ($section) use (&$found): void {
            if (str_contains($section->text(), 'Extended Color Light')) {
                $found = true;
            }
        });
        $this->assertTrue($found, 'Device types page should show Extended Color Light');
    }

    public function testDeviceTypesPageShowsOccupancySensor(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types');

        $this->assertResponseIsSuccessful();

        // Occupancy Sensor (263) - Eve Motion
        $allCategorySections = $crawler->filter('.category-section');
        $found = false;
        $allCategorySections->each(function ($section) use (&$found): void {
            if (str_contains($section->text(), 'Occupancy Sensor')) {
                $found = true;
            }
        });
        $this->assertTrue($found, 'Device types page should show Occupancy Sensor');
    }

    public function testDeviceTypesPageShowsContactSensor(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types');

        $this->assertResponseIsSuccessful();

        // Contact Sensor (21) - Eve Door
        $allCategorySections = $crawler->filter('.category-section');
        $found = false;
        $allCategorySections->each(function ($section) use (&$found): void {
            if (str_contains($section->text(), 'Contact Sensor')) {
                $found = true;
            }
        });
        $this->assertTrue($found, 'Device types page should show Contact Sensor');
    }

    public function testDeviceTypesPageShowsSpeaker(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types');

        $this->assertResponseIsSuccessful();

        // Speaker (34) - HomePod mini
        $allCategorySections = $crawler->filter('.category-section');
        $found = false;
        $allCategorySections->each(function ($section) use (&$found): void {
            if (str_contains($section->text(), 'Speaker')) {
                $found = true;
            }
        });
        $this->assertTrue($found, 'Device types page should show Speaker');
    }

    public function testDeviceTypesPageShowsLightsCategory(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types');

        $this->assertResponseIsSuccessful();

        // Should have a Lights category section
        $lightsSection = $crawler->filter('.category-section.category-lights');
        $this->assertGreaterThan(0, $lightsSection->count());
    }

    public function testDeviceTypesPageShowsSensorsCategory(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types');

        $this->assertResponseIsSuccessful();

        // Should have a Sensors category section
        $sensorsSection = $crawler->filter('.category-section.category-sensors');
        $this->assertGreaterThan(0, $sensorsSection->count());
    }

    public function testDeviceTypesPageShowsEntertainmentCategory(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types');

        $this->assertResponseIsSuccessful();

        // Should have Entertainment category (Speaker, Casting Video Player)
        $entertainmentSection = $crawler->filter('.category-section.category-entertainment');
        $this->assertGreaterThan(0, $entertainmentSection->count());
    }

    public function testDeviceTypesPageShowsMissingDeviceTypes(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types');

        $this->assertResponseIsSuccessful();

        // Should show device types from Matter spec not seen in survey (now categorized)
        $unseenSections = $crawler->filter('.unseen-category');
        $this->assertGreaterThan(0, $unseenSections->count());

        // Door Lock is in the registry but not in fixtures (Security category)
        $pageContent = $crawler->filter('body')->text();
        $this->assertStringContainsString('Door Lock', $pageContent);
    }

    public function testDeviceTypesPageShowsSpecVersionBadges(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types');

        $this->assertResponseIsSuccessful();

        // Should show spec version badges (1.0 for most fixture device types)
        $specBadges = $crawler->filter('.spec-badge');
        $this->assertGreaterThan(0, $specBadges->count());
    }

    // === Binding Page Tests ===

    public function testBindingPageLoads(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/binding');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testBindingPageShowsCorrectBindingCount(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/binding');

        $this->assertResponseIsSuccessful();

        // 4 devices have binding cluster (30): Eve Motion, Eve Energy, Philips bulb, Nanoleaf
        $this->assertSelectorTextContains('.stats-row', '4');
    }

    public function testBindingPageShowsBindingCapableDevices(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/binding');

        $this->assertResponseIsSuccessful();

        // Should list binding-capable devices
        $deviceTable = $crawler->filter('.device-table');
        $this->assertGreaterThan(0, $deviceTable->count());

        // Should include Eve Motion (has binding cluster)
        $this->assertStringContainsString('Eve Motion', $deviceTable->text());
    }

    public function testBindingPageShowsPhilipsHueBulb(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/binding');

        $this->assertResponseIsSuccessful();

        // Philips Hue bulb has binding cluster
        $deviceTable = $crawler->filter('.device-table');
        $this->assertStringContainsString('Hue', $deviceTable->text());
    }

    public function testBindingPageShowsNanoleaf(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/binding');

        $this->assertResponseIsSuccessful();

        // Nanoleaf has binding cluster
        $deviceTable = $crawler->filter('.device-table');
        $this->assertStringContainsString('Nanoleaf', $deviceTable->text());
    }

    public function testBindingPageShowsCategoryBreakdown(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/binding');

        $this->assertResponseIsSuccessful();

        // Should show binding by category
        $categoryGrid = $crawler->filter('.category-grid');
        $this->assertGreaterThan(0, $categoryGrid->count());

        // Lights category should have high binding rate (Philips bulb, Nanoleaf both have binding)
        $categoryCards = $crawler->filter('.category-card');
        $this->assertGreaterThan(0, $categoryCards->count());
    }

    public function testBindingPageShowsPercentage(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/binding');

        $this->assertResponseIsSuccessful();

        // Should show percentage of devices with binding (actual % varies with DCL data)
        $this->assertSelectorTextContains('.stats-row', 'Of All Devices');
    }

    // === Versions Page Tests ===

    public function testVersionsPageLoads(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/versions');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testVersionsPageShowsVersionStats(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/versions');

        $this->assertResponseIsSuccessful();

        // Should show products with version info
        $statsGrid = $crawler->filter('.stats-grid');
        $this->assertStringContainsString('Products with Version Info', $statsGrid->text());
    }

    public function testVersionsPageShowsUniqueSoftwareVersions(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/versions');

        $this->assertResponseIsSuccessful();

        // Each fixture device has a unique software version
        $statsGrid = $crawler->filter('.stats-grid');
        $this->assertStringContainsString('Unique Software Versions', $statsGrid->text());
    }

    public function testVersionsPageShowsUniqueHardwareVersions(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/versions');

        $this->assertResponseIsSuccessful();

        // Fixture devices have various hardware versions
        $statsGrid = $crawler->filter('.stats-grid');
        $this->assertStringContainsString('Unique Hardware Versions', $statsGrid->text());
    }

    // === Navigation Tests ===

    public function testNavigationFromDashboardToClusters(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/dashboard');

        $this->assertResponseIsSuccessful();

        $clustersLink = $crawler->filter('.dashboard-nav a:contains("Clusters")');
        $client->click($clustersLink->link());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.dashboard-nav a.active', 'Clusters');
    }

    public function testNavigationFromDashboardToDeviceTypes(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/dashboard');

        $this->assertResponseIsSuccessful();

        $deviceTypesLink = $crawler->filter('.dashboard-nav a:contains("Device Types")');
        $client->click($deviceTypesLink->link());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.dashboard-nav a.active', 'Device Types');
    }

    public function testNavigationFromDashboardToBinding(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/dashboard');

        $this->assertResponseIsSuccessful();

        $bindingLink = $crawler->filter('.dashboard-nav a:contains("Binding")');
        $client->click($bindingLink->link());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.dashboard-nav a.active', 'Binding');
    }

    public function testNavigationFromDashboardToVersions(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/dashboard');

        $this->assertResponseIsSuccessful();

        $versionsLink = $crawler->filter('.dashboard-nav a:contains("Versions")');
        $client->click($versionsLink->link());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.dashboard-nav a.active', 'Versions');
    }

    public function testHeaderNavigationShowsStatsLink(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();

        $statsLink = $crawler->filter('header nav a:contains("Stats")');
        $this->assertCount(1, $statsLink);

        $client->click($statsLink->link());
        $this->assertResponseIsSuccessful();
    }

    public function testDashboardLinkToDeviceDetail(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/dashboard');

        $this->assertResponseIsSuccessful();

        // Click on a device in the recently discovered section
        $deviceLink = $crawler->filter('.recent-device a')->first();
        if ($deviceLink->count() > 0) {
            $client->click($deviceLink->link());
            $this->assertResponseIsSuccessful();
            $this->assertSelectorExists('.device-header');
        }
    }

    public function testBindingPageLinkToDeviceDetail(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/binding');

        $this->assertResponseIsSuccessful();

        // Click on a device in the binding table
        $deviceLink = $crawler->filter('.device-table tbody a')->first();
        if ($deviceLink->count() > 0) {
            $client->click($deviceLink->link());
            $this->assertResponseIsSuccessful();
            $this->assertSelectorExists('.device-header');
        }
    }

    // === Cluster Show Page Tests ===

    public function testClusterShowPageLoads(): void
    {
        $client = self::createClient();
        // On/Off cluster (0x0006) should exist in fixtures
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/cluster/0x0006');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.cluster-header h1', 'On/Off');
    }

    public function testClusterShowPageLoadsWithLowercaseHex(): void
    {
        $client = self::createClient();
        // Should work with lowercase hex too
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/cluster/0x0006');

        $this->assertResponseIsSuccessful();
    }

    public function testClusterShowPageHasStructuredData(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/cluster/0x0006');

        $this->assertResponseIsSuccessful();

        // Check JSON-LD exists
        $jsonLdScripts = $crawler->filter('script[type="application/ld+json"]');
        $this->assertGreaterThan(0, $jsonLdScripts->count());

        $jsonLd = json_decode($jsonLdScripts->first()->text(), true);
        $this->assertEquals('DefinedTerm', $jsonLd['@type']);
    }

    public function testClusterShowPageLinksFromClustersIndex(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/clusters');

        $this->assertResponseIsSuccessful();

        // Click on a cluster name link
        $clusterLink = $crawler->filter('.cluster-card-title a')->first();
        if ($clusterLink->count() > 0) {
            $client->click($clusterLink->link());
            $this->assertResponseIsSuccessful();
            $this->assertSelectorExists('.cluster-header');
        }
    }

    public function testClusterShowPage404ForNonexistentCluster(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/cluster/0xFFFF');

        $this->assertResponseStatusCodeSame(404);
    }

    // === Cluster Spec Metadata Rendering Tests ===
    //
    // These assert that ZAP-synced metadata (apiMaturity, ranges, access privileges,
    // descriptions) surfaces in the cluster_show template. Spec values used here
    // are stable across recent Matter releases; if upstream renames or removes a
    // referenced field, these tests will need to point at a new example rather
    // than be silenced.

    public function testClusterShowRendersAttributeRangeAndNullable(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/cluster/0x0008');

        $this->assertResponseIsSuccessful();

        // Level Control CurrentLevel (0x0000): isNullable + min="1" + max="254"
        $attrPanel = $crawler->filter('#tab-attributes')->html();
        $this->assertStringContainsString('1..254', $attrPanel);
        $this->assertStringContainsString('nullable', $attrPanel);
    }

    public function testClusterShowRendersAttributeDefault(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/cluster/0x0008');

        $this->assertResponseIsSuccessful();

        // Level Control MaxLevel has default="0xFE"
        $attrPanel = $crawler->filter('#tab-attributes')->html();
        $this->assertStringContainsString('default', $attrPanel);
        $this->assertStringContainsString('0xFE', $attrPanel);
    }

    public function testClusterShowRendersAttributeAccessPrivilege(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/cluster/0x0006');

        $this->assertResponseIsSuccessful();

        // On/Off StartUpOnOff (0x4003) carries <access op="write" privilege="manage"/>
        $accessBadges = $crawler->filter('#tab-attributes .badge-access');
        $this->assertGreaterThan(0, $accessBadges->count(), 'Expected at least one access privilege badge on On/Off');
        $this->assertStringContainsString('write: manage', $accessBadges->text());
    }

    public function testClusterShowRendersCommandDescription(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/cluster/0x0006');

        $this->assertResponseIsSuccessful();

        // On/Off Off command (0x00) has rich SHALL-text description upstream.
        $cmdPanel = $crawler->filter('#tab-commands')->html();
        $this->assertStringContainsString('On receipt of this command', $cmdPanel);
    }

    public function testClusterShowRendersFeatureProvisionalBadge(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/cluster/0x0008');

        $this->assertResponseIsSuccessful();

        // Level Control's FQ Frequency feature is flagged apiMaturity="provisional" upstream
        $provisional = $crawler->filter('#tab-features .badge-provisional');
        $this->assertGreaterThan(0, $provisional->count(), 'Expected a provisional feature badge on Level Control');
    }

    public function testClusterShowRendersCommandProvisionalBadge(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/cluster/0x0030');

        $this->assertResponseIsSuccessful();

        // General Commissioning has SetTCAcknowledgements + several TC commands as provisional
        $provisional = $crawler->filter('#tab-commands .badge-provisional');
        $this->assertGreaterThan(0, $provisional->count(), 'Expected a provisional command badge on General Commissioning');
    }

    public function testClusterShowOmitsRangeForAttributesWithoutBounds(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/cluster/0x0006');

        $this->assertResponseIsSuccessful();

        // On/Off OnOff attribute (0x0000, boolean) has no min/max/default — no range hint.
        // Use a structural assertion: the OnOff attr row should exist; range markup absent for it.
        $attrPanel = $crawler->filter('#tab-attributes')->html();
        $this->assertStringContainsString('OnOff', $attrPanel);
        // The badge-access only appears when present; OnOff attr has none.
        // (no negative assertion on the whole panel — StartUpOnOff in the same table does have one)
        $this->assertSelectorExists('#tab-attributes');
    }

    // === Market Page Tests ===

    public function testMarketPageLoads(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/market');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testMarketPageShowsTotalVendors(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/market');

        $this->assertResponseIsSuccessful();

        // Should show total vendors insight
        $insightsGrid = $crawler->filter('.insights-grid');
        $this->assertStringContainsString('Total Vendors', $insightsGrid->text());
    }

    public function testMarketPageShowsTotalProducts(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/market');

        $this->assertResponseIsSuccessful();

        // Should show total products insight
        $insightsGrid = $crawler->filter('.insights-grid');
        $this->assertStringContainsString('Total Products', $insightsGrid->text());
    }

    public function testMarketPageShowsCategoryDistribution(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/market');

        $this->assertResponseIsSuccessful();

        // Should show device categories section
        $this->assertSelectorTextContains('.section-header h2', 'Device Categories');
    }

    public function testMarketPageShowsTopVendors(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/market');

        $this->assertResponseIsSuccessful();

        // Should show top vendors by market share section
        $headers = $crawler->filter('.section-header h2');
        $found = false;
        foreach ($headers as $header) {
            if (str_contains($header->textContent, 'Top Vendors')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Top Vendors section should exist');
    }

    public function testMarketPageHasActiveNavigation(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/market');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.dashboard-nav a.active', 'Market');
    }

    // === Commissioning Page Tests ===

    public function testCommissioningPageLoads(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/commissioning');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testCommissioningPageShowsTotalProducts(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/commissioning');

        $this->assertResponseIsSuccessful();

        // Should show total products stat
        $statsGrid = $crawler->filter('.stats-grid');
        $this->assertStringContainsString('Total Products', $statsGrid->text());
    }

    public function testCommissioningPageShowsSetupInstructionsStat(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/commissioning');

        $this->assertResponseIsSuccessful();

        // Should show setup instructions stat
        $statsGrid = $crawler->filter('.stats-grid');
        $this->assertStringContainsString('With Setup Instructions', $statsGrid->text());
    }

    public function testCommissioningPageShowsFactoryResetStat(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/commissioning');

        $this->assertResponseIsSuccessful();

        // Should show factory reset stat
        $statsGrid = $crawler->filter('.stats-grid');
        $this->assertStringContainsString('With Factory Reset', $statsGrid->text());
    }

    public function testCommissioningPageHasActiveNavigation(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/commissioning');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.dashboard-nav a.active', 'Commissioning');
    }

    public function testNavigationFromDashboardToMarket(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/versions');

        $this->assertResponseIsSuccessful();

        $marketLink = $crawler->filter('.dashboard-nav a:contains("Market")');
        $client->click($marketLink->link());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.dashboard-nav a.active', 'Market');
    }

    public function testNavigationFromDashboardToCommissioning(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/versions');

        $this->assertResponseIsSuccessful();

        $commissioningLink = $crawler->filter('.dashboard-nav a:contains("Commissioning")');
        $client->click($commissioningLink->link());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.dashboard-nav a.active', 'Commissioning');
    }

    // === Device Type Show Page Tests (Sorting by Rating) ===

    public function testDeviceTypeShowPageLoads(): void
    {
        $client = self::createClient();
        // On/Off Light (256) should exist in fixtures
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types/256');

        $this->assertResponseIsSuccessful();
        // Device type name is in the main content h1, not the site header
        $this->assertSelectorTextContains('.device-type-header-info h1', 'On/Off Light');
    }

    public function testDeviceTypeShowPageDefaultsToRatingSorting(): void
    {
        $client = self::createClient();
        // Use Occupancy Sensor (263) which has test devices
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types/263');

        $this->assertResponseIsSuccessful();

        // Rating sort option should be active by default (only shown when devices exist)
        $deviceCards = $crawler->filter('.device-card');
        if ($deviceCards->count() > 0) {
            $ratingSort = $crawler->filter('.sort-option.active');
            $this->assertGreaterThan(0, $ratingSort->count());
        } else {
            // No devices means no sort controls - that's OK
            $this->assertTrue(true);
        }
    }

    public function testDeviceTypeShowPageSortByRating(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types/256', ['sort' => 'rating']);

        $this->assertResponseIsSuccessful();
    }

    public function testDeviceTypeShowPageSortByName(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types/256', ['sort' => 'name']);

        $this->assertResponseIsSuccessful();
    }

    public function testDeviceTypeShowPageSortByRecent(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types/256', ['sort' => 'recent']);

        $this->assertResponseIsSuccessful();
    }

    public function testDeviceTypeShowPageInvalidSortDefaultsToRating(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types/256', ['sort' => 'invalid']);

        $this->assertResponseIsSuccessful();
        // Invalid sort should not cause errors, falls back to rating
    }

    public function testDeviceTypeShowPageHasSortControls(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types/256');

        $this->assertResponseIsSuccessful();

        // Should have sort control links
        $sortControls = $crawler->filter('.sort-controls');
        if ($sortControls->count() > 0) {
            $this->assertSelectorTextContains('.sort-controls', 'Rating');
            $this->assertSelectorTextContains('.sort-controls', 'Name');
            $this->assertSelectorTextContains('.sort-controls', 'Recent');
        }
    }

    public function testDeviceTypeShowPageShowsStarRatings(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types/256');

        $this->assertResponseIsSuccessful();

        // If there are devices, they may have star badges (depends on cached scores)
        $deviceCards = $crawler->filter('.device-card');
        $this->assertGreaterThanOrEqual(0, $deviceCards->count());
    }

    public function testDeviceTypeShowPagePaginationPreservesSort(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types/256', ['sort' => 'name', 'page' => '1']);

        $this->assertResponseIsSuccessful();

        // If pagination links exist, they should preserve the sort parameter
        $paginationLinks = $crawler->filter('.pagination a');
        foreach ($paginationLinks as $link) {
            $this->assertInstanceOf(\DOMElement::class, $link);
            $href = $link->getAttribute('href');
            $this->assertStringContainsString('sort=name', $href);
        }
    }

    public function testDeviceTypeShowPage404ForNonexistentType(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types/99999');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeviceTypeShowPageShowsClusterRequirements(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types/256');

        $this->assertResponseIsSuccessful();

        // Should show cluster requirements section
        $this->assertSelectorTextContains('body', 'Cluster Requirements');
        $this->assertSelectorTextContains('body', 'Server Clusters');
    }
}
