<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class CompareControllerTest extends WebTestCase
{
    public function testEmptyComparePageLoads(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Compare Devices');
        $this->assertSelectorTextContains('.compare-empty', 'Start Comparing Devices');
    }

    public function testComparePageWithSingleDevice(): void
    {
        $client = self::createClient();

        // First get a device slug from the index page
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $href = $deviceLink->attr('href');

        // Extract slug from href (format: /device/{slug})
        $slug = basename((string) $href);

        // Request compare page with single device
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare/'.$slug);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.compare-header', '1/5 devices selected');
    }

    public function testComparePageWithMultipleDevices(): void
    {
        $client = self::createClient();

        // Get two device slugs from the index page
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLinks = $crawler->filter('.device-info h3 a');

        if ($deviceLinks->count() < 2) {
            $this->markTestSkipped('Not enough devices in fixtures for this test');
        }

        $slug1 = basename((string) $deviceLinks->eq(0)->attr('href'));
        $slug2 = basename((string) $deviceLinks->eq(1)->attr('href'));

        // Request compare page with two devices
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare/'.$slug1.','.$slug2);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.compare-header', '2/5 devices selected');
        $this->assertSelectorExists('.compare-table');
    }

    public function testComparePageShowsDeviceHeaders(): void
    {
        $client = self::createClient();

        // Get two device slugs
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLinks = $crawler->filter('.device-info h3 a');

        if ($deviceLinks->count() < 2) {
            $this->markTestSkipped('Not enough devices in fixtures for this test');
        }

        $slug1 = basename((string) $deviceLinks->eq(0)->attr('href'));
        $slug2 = basename((string) $deviceLinks->eq(1)->attr('href'));

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare/'.$slug1.','.$slug2);

        $this->assertResponseIsSuccessful();

        // Check device headers are present
        $this->assertSelectorExists('.device-header-card');
        $this->assertSelectorExists('.device-header-card .device-name');
    }

    public function testComparePageShowsCapabilities(): void
    {
        $client = self::createClient();

        // Get device slug
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename((string) $deviceLink->attr('href'));

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare/'.$slug);

        $this->assertResponseIsSuccessful();

        // Check capability rows are present (categories)
        $this->assertSelectorExists('.category-row');
        $this->assertSelectorExists('.capability-row');
    }

    public function testComparePageShowsSummaryRow(): void
    {
        $client = self::createClient();

        // Get device slug
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename((string) $deviceLink->attr('href'));

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare/'.$slug);

        $this->assertResponseIsSuccessful();

        // Check summary section exists
        $this->assertSelectorTextContains('.compare-table', 'Summary');
        $this->assertSelectorTextContains('.compare-table', 'Feature Score');
    }

    public function testComparePageHasAddDeviceColumn(): void
    {
        $client = self::createClient();

        // Get device slug
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename((string) $deviceLink->attr('href'));

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare/'.$slug);

        $this->assertResponseIsSuccessful();

        // With 1 device, should show add device column
        $this->assertSelectorExists('.add-device-column');
        $this->assertSelectorExists('.add-device-search');
    }

    public function testComparePageWithInvalidSlugReturns404(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare/non-existent-device-slug');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testComparePageIgnoresInvalidSlugsInList(): void
    {
        $client = self::createClient();

        // Get a valid device slug
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename((string) $deviceLink->attr('href'));

        // Mix valid and invalid slugs
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare/'.$slug.',invalid-slug-123');

        // Should load successfully with just the valid device
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.compare-header', '1/5 devices selected');
    }

    public function testCompareSearchApiReturnsJson(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/api/compare/search', ['q' => 'Eve']);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $content = $client->getResponse()->getContent();
        $data = json_decode((string) $content, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('results', $data);
    }

    public function testCompareSearchApiFiltersExcludedDevices(): void
    {
        $client = self::createClient();

        // First get a device slug
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename((string) $deviceLink->attr('href'));

        // Search with the device excluded
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/api/compare/search', [
            'q' => substr($slug, 0, 3), // Use part of slug as search term
            'exclude' => $slug,
        ]);

        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        $data = json_decode((string) $content, true);

        // The excluded slug should not appear in results
        foreach ($data['results'] as $result) {
            $this->assertNotEquals($slug, $result['slug']);
        }
    }

    public function testCompareSearchApiRequiresMinLength(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/api/compare/search', ['q' => 'a']);

        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        $data = json_decode((string) $content, true);

        // Should return empty results for short queries
        $this->assertEmpty($data['results']);
    }

    public function testCompareAddDeviceRedirects(): void
    {
        $client = self::createClient();

        // Get a device slug
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename((string) $deviceLink->attr('href'));

        // Add device should redirect to compare page
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare/add/'.$slug);

        $this->assertResponseRedirects();
        $this->assertStringContainsString('/compare/', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testCompareAddDeviceAppendsToExisting(): void
    {
        $client = self::createClient();

        // Get two device slugs
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLinks = $crawler->filter('.device-info h3 a');

        if ($deviceLinks->count() < 2) {
            $this->markTestSkipped('Not enough devices in fixtures for this test');
        }

        $slug1 = basename((string) $deviceLinks->eq(0)->attr('href'));
        $slug2 = basename((string) $deviceLinks->eq(1)->attr('href'));

        // Add second device to first
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare/add/'.$slug2, ['current' => $slug1]);

        $this->assertResponseRedirects();

        $location = $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString($slug1, (string) $location);
        $this->assertStringContainsString($slug2, (string) $location);
    }

    public function testComparePageDeduplicatesSlugs(): void
    {
        $client = self::createClient();

        // Get a device slug
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename((string) $deviceLink->attr('href'));

        // Request with duplicate slugs
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare/'.$slug.','.$slug);

        $this->assertResponseIsSuccessful();

        // Should show only 1 device (deduplicated)
        $this->assertSelectorTextContains('.compare-header', '1/5 devices selected');
    }

    public function testComparePageMaxFiveDevices(): void
    {
        $client = self::createClient();

        // Get device slugs
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLinks = $crawler->filter('.device-info h3 a');

        if ($deviceLinks->count() < 6) {
            $this->markTestSkipped('Not enough devices in fixtures for this test');
        }

        $slugs = [];
        for ($i = 0; $i < 6; ++$i) {
            $slugs[] = basename((string) $deviceLinks->eq($i)->attr('href'));
        }

        // Request with 6 slugs
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare/'.implode(',', $slugs));

        $this->assertResponseIsSuccessful();

        // Should show only 5 devices (max)
        $this->assertSelectorTextContains('.compare-header', '5/5 devices selected');
    }

    public function testDeviceIndexPageHasCompareCheckboxes(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();

        // Check that compare checkboxes are present
        $this->assertSelectorExists('.compare-checkbox input[type="checkbox"]');
        $this->assertSelectorExists('[data-controller="compare-select"]');
    }

    public function testComparePageHasCopyLinkButton(): void
    {
        $client = self::createClient();

        // Get a device slug
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename((string) $deviceLink->attr('href'));

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare/'.$slug);

        $this->assertResponseIsSuccessful();

        // Check copy link button exists
        $this->assertSelectorExists('[data-action="compare#copyUrl"]');
    }

    public function testComparePageHasRemoveDeviceButtons(): void
    {
        $client = self::createClient();

        // Get a device slug
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename((string) $deviceLink->attr('href'));

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare/'.$slug);

        $this->assertResponseIsSuccessful();

        // Check remove button exists
        $this->assertSelectorExists('.remove-device');
    }

    public function testComparePageDeviceLinksWork(): void
    {
        $client = self::createClient();

        // Get a device slug
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename((string) $deviceLink->attr('href'));

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/compare/'.$slug);

        $this->assertResponseIsSuccessful();

        // Click on device name link in header
        $deviceNameLink = $crawler->filter('.device-header-card .device-name')->first();
        $client->click($deviceNameLink->link());

        // Should navigate to device detail page
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.device-header');
    }
}
