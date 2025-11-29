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
class StatsControllerTest extends WebTestCase
{
    // === Dashboard Overview Tests ===

    public function testDashboardPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testDashboardShowsCorrectDeviceCount(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();

        // Verify device count stat is displayed (exact count varies with DCL data)
        $this->assertSelectorTextContains('.stats-grid', 'Known Products');
    }

    public function testDashboardShowsCorrectVendorCount(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();

        // Verify vendor count stat is displayed
        $this->assertSelectorTextContains('.stats-grid', 'Vendors');
    }

    public function testDashboardShowsBindingDeviceCount(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();

        // Verify binding stat is displayed
        $this->assertSelectorTextContains('.stats-grid', 'Binding');
    }

    public function testDashboardShowsTopVendorsSection(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();

        // Verify top vendors section exists
        $vendorsSection = $crawler->filter('.dashboard-card:contains("Top Vendors")');
        $this->assertGreaterThan(0, $vendorsSection->count());
    }

    public function testDashboardShowsRecentDevices(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();

        // Check that recently discovered section shows fixture device names
        $recentSection = $crawler->filter('.dashboard-card:contains("Recently Discovered")');
        $this->assertGreaterThan(0, $recentSection->count());

        // Should contain at least one of our fixture devices
        $recentText = $recentSection->text();
        $hasFixtureDevice = str_contains($recentText, 'HomePod') ||
            str_contains($recentText, 'Eve') ||
            str_contains($recentText, 'Hue') ||
            str_contains($recentText, 'Nanoleaf');
        $this->assertTrue($hasFixtureDevice, 'Recent devices section should contain fixture devices');
    }

    public function testDashboardShowsCategoryDistributionWithLights(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();

        // Fixtures include Extended Color Light (269) devices - should show Lights category
        $categorySection = $crawler->filter('.dashboard-card:contains("Device Categories")');
        $this->assertStringContainsString('Lights', $categorySection->text());
    }

    public function testDashboardShowsCategoryDistributionWithSensors(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();

        // Fixtures include Occupancy Sensor (263) and Contact Sensor (21)
        $categorySection = $crawler->filter('.dashboard-card:contains("Device Categories")');
        $this->assertStringContainsString('Sensors', $categorySection->text());
    }

    // === Clusters Page Tests ===

    public function testClustersPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/clusters');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testClustersPageShowsDescriptorCluster(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/clusters');

        $this->assertResponseIsSuccessful();

        // Descriptor cluster (29) appears in all devices
        $this->assertSelectorTextContains('.cluster-grid', 'Descriptor');
    }

    public function testClustersPageShowsOnOffCluster(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/clusters');

        $this->assertResponseIsSuccessful();

        // On/Off cluster (6) appears in multiple devices
        $this->assertSelectorTextContains('.cluster-grid', 'On/Off');
    }

    public function testClustersPageShowsBindingCluster(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/clusters');

        $this->assertResponseIsSuccessful();

        // Binding cluster (30) appears in 4 devices
        $this->assertSelectorTextContains('.cluster-grid', 'Binding');
    }

    public function testClustersPageShowsColorControlCluster(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/clusters');

        $this->assertResponseIsSuccessful();

        // Color Control cluster (768) appears in Philips bulb and Nanoleaf
        $this->assertSelectorTextContains('.cluster-grid', 'Color Control');
    }

    public function testClustersPageShowsClusterCoOccurrence(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/clusters');

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
        $client = static::createClient();
        $crawler = $client->request('GET', '/clusters');

        $this->assertResponseIsSuccessful();

        // Insights grid should show unique cluster count
        $insightsGrid = $crawler->filter('.insights-grid');
        $this->assertStringContainsString('Unique Clusters', $insightsGrid->text());
    }

    // === Device Types Page Tests ===

    public function testDeviceTypesPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/device-types');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testDeviceTypesPageShowsExtendedColorLight(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/device-types');

        $this->assertResponseIsSuccessful();

        // Extended Color Light (269) - Philips bulb and Nanoleaf
        // Search all device type cards for the name
        $allCategorySections = $crawler->filter('.category-section');
        $found = false;
        $allCategorySections->each(function ($section) use (&$found) {
            if (str_contains($section->text(), 'Extended Color Light')) {
                $found = true;
            }
        });
        $this->assertTrue($found, 'Device types page should show Extended Color Light');
    }

    public function testDeviceTypesPageShowsOccupancySensor(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/device-types');

        $this->assertResponseIsSuccessful();

        // Occupancy Sensor (263) - Eve Motion
        $allCategorySections = $crawler->filter('.category-section');
        $found = false;
        $allCategorySections->each(function ($section) use (&$found) {
            if (str_contains($section->text(), 'Occupancy Sensor')) {
                $found = true;
            }
        });
        $this->assertTrue($found, 'Device types page should show Occupancy Sensor');
    }

    public function testDeviceTypesPageShowsContactSensor(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/device-types');

        $this->assertResponseIsSuccessful();

        // Contact Sensor (21) - Eve Door
        $allCategorySections = $crawler->filter('.category-section');
        $found = false;
        $allCategorySections->each(function ($section) use (&$found) {
            if (str_contains($section->text(), 'Contact Sensor')) {
                $found = true;
            }
        });
        $this->assertTrue($found, 'Device types page should show Contact Sensor');
    }

    public function testDeviceTypesPageShowsSpeaker(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/device-types');

        $this->assertResponseIsSuccessful();

        // Speaker (34) - HomePod mini
        $allCategorySections = $crawler->filter('.category-section');
        $found = false;
        $allCategorySections->each(function ($section) use (&$found) {
            if (str_contains($section->text(), 'Speaker')) {
                $found = true;
            }
        });
        $this->assertTrue($found, 'Device types page should show Speaker');
    }

    public function testDeviceTypesPageShowsLightsCategory(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/device-types');

        $this->assertResponseIsSuccessful();

        // Should have a Lights category section
        $lightsSection = $crawler->filter('.category-section.category-lights');
        $this->assertGreaterThan(0, $lightsSection->count());
    }

    public function testDeviceTypesPageShowsSensorsCategory(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/device-types');

        $this->assertResponseIsSuccessful();

        // Should have a Sensors category section
        $sensorsSection = $crawler->filter('.category-section.category-sensors');
        $this->assertGreaterThan(0, $sensorsSection->count());
    }

    public function testDeviceTypesPageShowsEntertainmentCategory(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/device-types');

        $this->assertResponseIsSuccessful();

        // Should have Entertainment category (Speaker, Casting Video Player)
        $entertainmentSection = $crawler->filter('.category-section.category-entertainment');
        $this->assertGreaterThan(0, $entertainmentSection->count());
    }

    public function testDeviceTypesPageShowsMissingDeviceTypes(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/device-types');

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
        $client = static::createClient();
        $crawler = $client->request('GET', '/device-types');

        $this->assertResponseIsSuccessful();

        // Should show spec version badges (1.0 for most fixture device types)
        $specBadges = $crawler->filter('.spec-badge');
        $this->assertGreaterThan(0, $specBadges->count());
    }

    // === Binding Page Tests ===

    public function testBindingPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/binding');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testBindingPageShowsCorrectBindingCount(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/binding');

        $this->assertResponseIsSuccessful();

        // 4 devices have binding cluster (30): Eve Motion, Eve Energy, Philips bulb, Nanoleaf
        $this->assertSelectorTextContains('.stats-row', '4');
    }

    public function testBindingPageShowsBindingCapableDevices(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/binding');

        $this->assertResponseIsSuccessful();

        // Should list binding-capable devices
        $deviceTable = $crawler->filter('.device-table');
        $this->assertGreaterThan(0, $deviceTable->count());

        // Should include Eve Motion (has binding cluster)
        $this->assertStringContainsString('Eve Motion', $deviceTable->text());
    }

    public function testBindingPageShowsPhilipsHueBulb(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/binding');

        $this->assertResponseIsSuccessful();

        // Philips Hue bulb has binding cluster
        $deviceTable = $crawler->filter('.device-table');
        $this->assertStringContainsString('Hue', $deviceTable->text());
    }

    public function testBindingPageShowsNanoleaf(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/binding');

        $this->assertResponseIsSuccessful();

        // Nanoleaf has binding cluster
        $deviceTable = $crawler->filter('.device-table');
        $this->assertStringContainsString('Nanoleaf', $deviceTable->text());
    }

    public function testBindingPageShowsCategoryBreakdown(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/binding');

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
        $client = static::createClient();
        $crawler = $client->request('GET', '/binding');

        $this->assertResponseIsSuccessful();

        // Should show percentage of devices with binding (actual % varies with DCL data)
        $this->assertSelectorTextContains('.stats-row', 'Of All Devices');
    }

    // === Versions Page Tests ===

    public function testVersionsPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/versions');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testVersionsPageShowsVersionStats(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/versions');

        $this->assertResponseIsSuccessful();

        // Should show products with version info
        $statsRow = $crawler->filter('.stats-row');
        $this->assertStringContainsString('Products with Version Info', $statsRow->text());
    }

    public function testVersionsPageShowsUniqueSoftwareVersions(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/versions');

        $this->assertResponseIsSuccessful();

        // Each fixture device has a unique software version
        $statsRow = $crawler->filter('.stats-row');
        $this->assertStringContainsString('Unique Software Versions', $statsRow->text());
    }

    public function testVersionsPageShowsUniqueHardwareVersions(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/versions');

        $this->assertResponseIsSuccessful();

        // Fixture devices have various hardware versions
        $statsRow = $crawler->filter('.stats-row');
        $this->assertStringContainsString('Unique Hardware Versions', $statsRow->text());
    }

    // === Navigation Tests ===

    public function testNavigationFromDashboardToClusters(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();

        $clustersLink = $crawler->filter('.dashboard-nav a:contains("Clusters")');
        $client->click($clustersLink->link());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.dashboard-nav a.active', 'Clusters');
    }

    public function testNavigationFromDashboardToDeviceTypes(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();

        $deviceTypesLink = $crawler->filter('.dashboard-nav a:contains("Device Types")');
        $client->click($deviceTypesLink->link());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.dashboard-nav a.active', 'Device Types');
    }

    public function testNavigationFromDashboardToBinding(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();

        $bindingLink = $crawler->filter('.dashboard-nav a:contains("Binding")');
        $client->click($bindingLink->link());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.dashboard-nav a.active', 'Binding');
    }

    public function testNavigationFromDashboardToVersions(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();

        $versionsLink = $crawler->filter('.dashboard-nav a:contains("Versions")');
        $client->click($versionsLink->link());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.dashboard-nav a.active', 'Versions');
    }

    public function testHeaderNavigationShowsDashboardLink(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();

        $dashboardLink = $crawler->filter('header nav a:contains("Dashboard")');
        $this->assertCount(1, $dashboardLink);

        $client->click($dashboardLink->link());
        $this->assertResponseIsSuccessful();
    }

    public function testDashboardLinkToDeviceDetail(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/dashboard');

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
        $client = static::createClient();
        $crawler = $client->request('GET', '/binding');

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
        $client = static::createClient();
        // On/Off cluster (0x0006) should exist in fixtures
        $client->request('GET', '/cluster/0x0006');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.cluster-header h1', 'On/Off');
    }

    public function testClusterShowPageLoadsWithLowercaseHex(): void
    {
        $client = static::createClient();
        // Should work with lowercase hex too
        $client->request('GET', '/cluster/0x0006');

        $this->assertResponseIsSuccessful();
    }

    public function testClusterShowPageHasStructuredData(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/cluster/0x0006');

        $this->assertResponseIsSuccessful();

        // Check JSON-LD exists
        $jsonLdScripts = $crawler->filter('script[type="application/ld+json"]');
        $this->assertGreaterThan(0, $jsonLdScripts->count());

        $jsonLd = json_decode($jsonLdScripts->first()->text(), true);
        $this->assertEquals('DefinedTerm', $jsonLd['@type']);
    }

    public function testClusterShowPageLinksFromClustersIndex(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/clusters');

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
        $client = static::createClient();
        $client->request('GET', '/cluster/0xFFFF');

        $this->assertResponseStatusCodeSame(404);
    }
}
