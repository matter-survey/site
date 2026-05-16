<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

final class VendorControllerTest extends KernelTestCase
{
    use HasBrowser;

    public function testVendorsIndexPageLoads(): void
    {
        $this->browser()
            ->visit('/vendors')
            ->assertSuccessful()
            ->assertSeeElement('html')
            ->assertSeeIn('.page-header h1', 'Vendors');
    }

    public function testVendorsIndexShowsFixtureVendors(): void
    {
        $this->browser()
            ->visit('/vendors')
            ->assertSuccessful()
            ->assertSeeIn('.vendors-section', 'Apple Home')
            ->assertSeeIn('.vendors-section', 'Eve')
            ->assertSeeIn('.vendors-section', 'Signify')
            ->assertSeeIn('.vendors-section', 'Nanoleaf')
            ->assertSeeIn('.page-subtitle', 'vendors');
    }

    public function testVendorShowPageWithFixtureData(): void
    {
        $this->browser()
            ->visit('/vendor/eve-4874')
            ->assertSuccessful()
            ->assertSeeIn('.vendor-header h1', 'Eve')
            ->assertSeeIn('.vendor-header .meta', 'Vendor ID: 4874')
            ->assertSeeIn('.vendor-header .meta', '3 devices')
            ->assertSeeIn('.device-list', 'Eve Motion')
            ->assertSeeIn('.device-list', 'Eve Energy')
            ->assertSeeIn('.device-list', 'Eve Door & Window');
    }

    public function testVendorShowPageDisplaysDeviceCount(): void
    {
        $this->browser()
            ->visit('/vendor/apple-home-4937')
            ->assertSuccessful()
            ->assertSeeIn('.vendor-header h1', 'Apple Home')
            ->assertSeeIn('.vendor-header .meta', '2 devices');
    }

    public function testVendorShowNotFound(): void
    {
        $this->browser()
            ->visit('/vendor/non-existent-vendor-slug')
            ->assertStatus(404);
    }

    public function testVendorShowDevicesHaveCorrectLinks(): void
    {
        $browser = $this->browser()
            ->visit('/vendor/signify-4107')
            ->assertSuccessful();

        // Drop to the underlying crawler for the per-link regex check.
        $deviceLinks = $browser->client()
            ->getCrawler()
            ->filter('.device-info h3 a');
        $this->assertGreaterThan(0, $deviceLinks->count());
        $deviceLinks->each(function ($node): void {
            $href = $node->attr('href');
            $this->assertMatchesRegularExpression('/^\/device\/[a-z0-9-]+$/', $href);
        });
    }

    public function testVendorIndexLinksToVendorPages(): void
    {
        // Click the first vendor name link; Zenstruck's click() takes the
        // visible link text. We don't know which vendor will be first, so
        // assert the destination by structure rather than name.
        $browser = $this->browser()
            ->visit('/vendors')
            ->assertSuccessful();

        $firstLink = $browser->client()
            ->getCrawler()
            ->filter('.vendor-name a')
            ->first()
            ->link();

        $browser->client()->click($firstLink);
        $browser->assertSuccessful()->assertSeeElement('.vendor-header');
    }
}
