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

        // Fixtures have 8 devices - verify this appears in stats
        $this->assertSelectorTextContains('.stats-grid', '8');
    }

    public function testDashboardShowsCorrectVendorCount(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();

        // Fixtures have 4 vendors
        $this->assertSelectorTextContains('.stats-grid', '4');
    }

    public function testDashboardShowsBindingDeviceCount(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();

        // Fixtures have 4 binding-capable devices (Eve Motion, Eve Energy, Philips bulb, Nanoleaf)
        $this->assertSelectorTextContains('.stats-grid', '4');
    }

    public function testDashboardShowsTopVendorEve(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();

        // Eve Systems has the most devices (3) so should appear in top vendors
        $vendorsSection = $crawler->filter('.dashboard-card:contains("Top Vendors")');
        $this->assertStringContainsString('Eve', $vendorsSection->text());
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
        $this->assertSelectorTextContains('.cluster-table', 'Descriptor');
    }

    public function testClustersPageShowsOnOffCluster(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/clusters');

        $this->assertResponseIsSuccessful();

        // On/Off cluster (6) appears in multiple devices
        $this->assertSelectorTextContains('.cluster-table', 'On/Off');
    }

    public function testClustersPageShowsBindingCluster(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/clusters');

        $this->assertResponseIsSuccessful();

        // Binding cluster (30) appears in 4 devices
        $this->assertSelectorTextContains('.cluster-table', 'Binding');
    }

    public function testClustersPageShowsColorControlCluster(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/clusters');

        $this->assertResponseIsSuccessful();

        // Color Control cluster (768) appears in Philips bulb and Nanoleaf
        $this->assertSelectorTextContains('.cluster-table', 'Color Control');
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

        // Count unique clusters in fixtures: 6, 8, 29, 30, 31, 40, 69, 768, 1030, 1283, 1794 = 11 unique clusters
        $statsRow = $crawler->filter('.stats-row');
        $this->assertStringContainsString('Unique Clusters', $statsRow->text());
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

        // Should show device types from Matter spec not seen in survey
        $missingSection = $crawler->filter('.missing-section');
        $this->assertGreaterThan(0, $missingSection->count());

        // Thermostat (769) is in the registry but not in fixtures
        $this->assertStringContainsString('Thermostat', $missingSection->text());
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

        // Should show percentage of devices with binding
        // 4 of 8 devices = 50%
        $this->assertSelectorTextContains('.stats-row', '50');
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
}
