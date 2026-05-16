<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

final class SitemapControllerTest extends KernelTestCase
{
    use HasBrowser;

    public function testIndexListsAllSubSitemaps(): void
    {
        $this->browser()
            ->visit('/sitemap.xml')
            ->assertSuccessful()
            ->assertHeaderContains('Content-Type', 'application/xml')
            ->assertContains('<sitemapindex')
            ->assertContains('sitemap-pages.xml')
            ->assertContains('sitemap-devices.xml')
            ->assertContains('sitemap-vendors.xml')
            ->assertContains('sitemap-specs.xml');
    }

    public function testIndexSetsSharedMaxAgeOneHour(): void
    {
        $this->browser()
            ->visit('/sitemap.xml')
            ->assertHeaderContains('Cache-Control', 's-maxage=3600');
    }

    public function testPagesSitemapIncludesStaticAndStatsPages(): void
    {
        $this->browser()
            ->visit('/sitemap-pages.xml')
            ->assertSuccessful()
            ->assertHeaderContains('Content-Type', 'application/xml')
            ->assertContains('<urlset')
            ->assertContains('/about')
            ->assertContains('/faq')
            ->assertContains('/glossary')
            ->assertContains('/dashboard')
            ->assertContains('changefreq')
            ->assertContains('priority');
    }

    public function testPagesSitemapAdvertisesLocaleAlternates(): void
    {
        $this->browser()
            ->visit('/sitemap-pages.xml')
            ->assertContains('hreflang="en"')
            ->assertContains('hreflang="de"')
            ->assertContains('hreflang="x-default"');
    }

    public function testDevicesSitemapListsDeviceSlugs(): void
    {
        $this->browser()
            ->visit('/sitemap-devices.xml')
            ->assertSuccessful()
            ->assertHeaderContains('Content-Type', 'application/xml')
            ->assertContains('<urlset')
            // Fixture has 8 devices with slugs; assert at least one made it in
            ->assertContains('/device/');
    }

    public function testVendorsSitemapListsVendorSlugs(): void
    {
        $this->browser()
            ->visit('/sitemap-vendors.xml')
            ->assertSuccessful()
            ->assertHeaderContains('Content-Type', 'application/xml')
            ->assertContains('<urlset')
            ->assertContains('/vendor/');
    }

    public function testSpecsSitemapListsDeviceTypesAndClusters(): void
    {
        $this->browser()
            ->visit('/sitemap-specs.xml')
            ->assertSuccessful()
            ->assertHeaderContains('Content-Type', 'application/xml')
            ->assertContains('<urlset')
            // Device-type listings live under stats/device-types/{id}
            ->assertContains('/device-types/')
            // Cluster listings live under stats/cluster/{hexId}
            ->assertContains('/cluster/');
    }
}
