<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class CompareControllerTest extends WebTestCase
{
    public function testEmptyComparePageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/compare');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Compare Devices');
        $this->assertSelectorTextContains('.compare-empty', 'Start Comparing Devices');
    }

    public function testComparePageWithSingleDevice(): void
    {
        $client = static::createClient();

        // First get a device slug from the index page
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $href = $deviceLink->attr('href');

        // Extract slug from href (format: /device/{slug})
        $slug = basename($href);

        // Request compare page with single device
        $client->request('GET', '/compare/'.$slug);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.compare-header', '1/5 devices selected');
    }

    public function testComparePageWithMultipleDevices(): void
    {
        $client = static::createClient();

        // Get two device slugs from the index page
        $crawler = $client->request('GET', '/');
        $deviceLinks = $crawler->filter('.device-info h3 a');

        if ($deviceLinks->count() < 2) {
            $this->markTestSkipped('Not enough devices in fixtures for this test');
        }

        $slug1 = basename($deviceLinks->eq(0)->attr('href'));
        $slug2 = basename($deviceLinks->eq(1)->attr('href'));

        // Request compare page with two devices
        $client->request('GET', '/compare/'.$slug1.','.$slug2);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.compare-header', '2/5 devices selected');
        $this->assertSelectorExists('.compare-table');
    }

    public function testComparePageShowsDeviceHeaders(): void
    {
        $client = static::createClient();

        // Get two device slugs
        $crawler = $client->request('GET', '/');
        $deviceLinks = $crawler->filter('.device-info h3 a');

        if ($deviceLinks->count() < 2) {
            $this->markTestSkipped('Not enough devices in fixtures for this test');
        }

        $slug1 = basename($deviceLinks->eq(0)->attr('href'));
        $slug2 = basename($deviceLinks->eq(1)->attr('href'));

        $crawler = $client->request('GET', '/compare/'.$slug1.','.$slug2);

        $this->assertResponseIsSuccessful();

        // Check device headers are present
        $this->assertSelectorExists('.device-header-card');
        $this->assertSelectorExists('.device-header-card .device-name');
    }

    public function testComparePageShowsCapabilities(): void
    {
        $client = static::createClient();

        // Get device slug
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename($deviceLink->attr('href'));

        $crawler = $client->request('GET', '/compare/'.$slug);

        $this->assertResponseIsSuccessful();

        // Check capability rows are present (categories)
        $this->assertSelectorExists('.category-row');
        $this->assertSelectorExists('.capability-row');
    }

    public function testComparePageShowsSummaryRow(): void
    {
        $client = static::createClient();

        // Get device slug
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename($deviceLink->attr('href'));

        $crawler = $client->request('GET', '/compare/'.$slug);

        $this->assertResponseIsSuccessful();

        // Check summary section exists
        $this->assertSelectorTextContains('.compare-table', 'Summary');
        $this->assertSelectorTextContains('.compare-table', 'Feature Score');
    }

    public function testComparePageHasAddDeviceColumn(): void
    {
        $client = static::createClient();

        // Get device slug
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename($deviceLink->attr('href'));

        $crawler = $client->request('GET', '/compare/'.$slug);

        $this->assertResponseIsSuccessful();

        // With 1 device, should show add device column
        $this->assertSelectorExists('.add-device-column');
        $this->assertSelectorExists('.add-device-search');
    }

    public function testComparePageWithInvalidSlugReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/compare/non-existent-device-slug');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testComparePageIgnoresInvalidSlugsInList(): void
    {
        $client = static::createClient();

        // Get a valid device slug
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename($deviceLink->attr('href'));

        // Mix valid and invalid slugs
        $client->request('GET', '/compare/'.$slug.',invalid-slug-123');

        // Should load successfully with just the valid device
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.compare-header', '1/5 devices selected');
    }

    public function testCompareSearchApiReturnsJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/compare/search', ['q' => 'Eve']);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $content = $client->getResponse()->getContent();
        $data = json_decode($content, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('results', $data);
    }

    public function testCompareSearchApiFiltersExcludedDevices(): void
    {
        $client = static::createClient();

        // First get a device slug
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename($deviceLink->attr('href'));

        // Search with the device excluded
        $client->request('GET', '/api/compare/search', [
            'q' => substr($slug, 0, 3), // Use part of slug as search term
            'exclude' => $slug,
        ]);

        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        $data = json_decode($content, true);

        // The excluded slug should not appear in results
        foreach ($data['results'] as $result) {
            $this->assertNotEquals($slug, $result['slug']);
        }
    }

    public function testCompareSearchApiRequiresMinLength(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/compare/search', ['q' => 'a']);

        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        $data = json_decode($content, true);

        // Should return empty results for short queries
        $this->assertEmpty($data['results']);
    }

    public function testCompareAddDeviceRedirects(): void
    {
        $client = static::createClient();

        // Get a device slug
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename($deviceLink->attr('href'));

        // Add device should redirect to compare page
        $client->request('GET', '/compare/add/'.$slug);

        $this->assertResponseRedirects();
        $this->assertStringContainsString('/compare/', $client->getResponse()->headers->get('Location'));
    }

    public function testCompareAddDeviceAppendsToExisting(): void
    {
        $client = static::createClient();

        // Get two device slugs
        $crawler = $client->request('GET', '/');
        $deviceLinks = $crawler->filter('.device-info h3 a');

        if ($deviceLinks->count() < 2) {
            $this->markTestSkipped('Not enough devices in fixtures for this test');
        }

        $slug1 = basename($deviceLinks->eq(0)->attr('href'));
        $slug2 = basename($deviceLinks->eq(1)->attr('href'));

        // Add second device to first
        $client->request('GET', '/compare/add/'.$slug2, ['current' => $slug1]);

        $this->assertResponseRedirects();

        $location = $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString($slug1, $location);
        $this->assertStringContainsString($slug2, $location);
    }

    public function testComparePageDeduplicatesSlugs(): void
    {
        $client = static::createClient();

        // Get a device slug
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename($deviceLink->attr('href'));

        // Request with duplicate slugs
        $client->request('GET', '/compare/'.$slug.','.$slug);

        $this->assertResponseIsSuccessful();

        // Should show only 1 device (deduplicated)
        $this->assertSelectorTextContains('.compare-header', '1/5 devices selected');
    }

    public function testComparePageMaxFiveDevices(): void
    {
        $client = static::createClient();

        // Get device slugs
        $crawler = $client->request('GET', '/');
        $deviceLinks = $crawler->filter('.device-info h3 a');

        if ($deviceLinks->count() < 6) {
            $this->markTestSkipped('Not enough devices in fixtures for this test');
        }

        $slugs = [];
        for ($i = 0; $i < 6; ++$i) {
            $slugs[] = basename($deviceLinks->eq($i)->attr('href'));
        }

        // Request with 6 slugs
        $client->request('GET', '/compare/'.implode(',', $slugs));

        $this->assertResponseIsSuccessful();

        // Should show only 5 devices (max)
        $this->assertSelectorTextContains('.compare-header', '5/5 devices selected');
    }

    public function testDeviceIndexPageHasCompareCheckboxes(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();

        // Check that compare checkboxes are present
        $this->assertSelectorExists('.compare-checkbox input[type="checkbox"]');
        $this->assertSelectorExists('[data-controller="compare-select"]');
    }

    public function testComparePageHasCopyLinkButton(): void
    {
        $client = static::createClient();

        // Get a device slug
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename($deviceLink->attr('href'));

        $client->request('GET', '/compare/'.$slug);

        $this->assertResponseIsSuccessful();

        // Check copy link button exists
        $this->assertSelectorExists('[data-action="compare#copyUrl"]');
    }

    public function testComparePageHasRemoveDeviceButtons(): void
    {
        $client = static::createClient();

        // Get a device slug
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename($deviceLink->attr('href'));

        $client->request('GET', '/compare/'.$slug);

        $this->assertResponseIsSuccessful();

        // Check remove button exists
        $this->assertSelectorExists('.remove-device');
    }

    public function testComparePageDeviceLinksWork(): void
    {
        $client = static::createClient();

        // Get a device slug
        $crawler = $client->request('GET', '/');
        $deviceLink = $crawler->filter('.device-info h3 a')->first();
        $slug = basename($deviceLink->attr('href'));

        $crawler = $client->request('GET', '/compare/'.$slug);

        $this->assertResponseIsSuccessful();

        // Click on device name link in header
        $deviceNameLink = $crawler->filter('.device-header-card .device-name')->first();
        $client->click($deviceNameLink->link());

        // Should navigate to device detail page
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.device-header');
    }
}
