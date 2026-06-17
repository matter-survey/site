<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for the AEO change: every public entity page renders
 * a visible `.aeo-lede` sentence in the first 30% of the body, a visible
 * `time datetime` attribution element, a service-rendered JSON-LD payload
 * whose `description` equals the lede, and a BreadcrumbList JSON-LD block.
 * `dateModified` is emitted as an ISO-8601 date.
 *
 * Aggregate stats pages additionally emit a Dataset JSON-LD block.
 *
 * Uses fixture vendor `eve-4874` (Eve Systems), fixture cluster 0x0006
 * (OnOff), fixture device type 22 (Root Node), and the first device from
 * the index for the device-page assertions.
 */
final class AeoIntegrationTest extends WebTestCase
{
    private const string FIXTURE_VENDOR_SLUG = 'eve-4874';
    private const string FIXTURE_CLUSTER_HEX = '0x0006';
    private const int FIXTURE_DEVICE_TYPE_ID = 22;

    public function testVendorPageHasLedeAndStructuredData(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/vendor/'.self::FIXTURE_VENDOR_SLUG);

        $this->assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();
        $this->assertLedeInFirstThirty($body);
        $this->assertHasVisibleTimeElement($crawler);

        [$organization, $breadcrumb] = $this->extractEntityJsonLdPair($body, 'Organization');

        $this->assertSame('Organization', $organization['@type']);
        $this->assertStringContainsString('Eve', (string) $organization['name']);
        $this->assertArrayHasKey('description', $organization);
        $this->assertArrayHasKey('dateModified', $organization);
        $this->assertMatchesIsoDate($organization['dateModified']);

        $ledeText = $this->ledeText($crawler);
        $this->assertSame($ledeText, $organization['description'], 'JSON-LD description must match visible lede');

        $this->assertSame('BreadcrumbList', $breadcrumb['@type']);
        $this->assertGreaterThan(0, \count($breadcrumb['itemListElement']));
    }

    public function testDevicePageHasLedeAndStructuredData(): void
    {
        $client = self::createClient();

        $indexCrawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');
        $this->assertResponseIsSuccessful();

        $deviceLink = $indexCrawler->filter('.device-info h3 a')->first();
        $href = $deviceLink->attr('href');
        $this->assertNotNull($href, 'device index must link to at least one device');

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $href);
        $this->assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();
        $this->assertLedeInFirstThirty($body);
        $this->assertHasVisibleTimeElement($crawler);

        [$product, $breadcrumb] = $this->extractEntityJsonLdPair($body, 'Product');

        $this->assertSame('Product', $product['@type']);
        $this->assertArrayHasKey('description', $product);
        $this->assertArrayHasKey('dateModified', $product);
        $this->assertMatchesIsoDate($product['dateModified']);

        $ledeText = $this->ledeText($crawler);
        $this->assertSame($ledeText, $product['description']);

        $this->assertSame('BreadcrumbList', $breadcrumb['@type']);
    }

    public function testClusterPageHasLedeAndStructuredData(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/cluster/'.self::FIXTURE_CLUSTER_HEX);

        $this->assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();
        $this->assertLedeInFirstThirty($body);
        $this->assertHasVisibleTimeElement($crawler);

        [$definedTerm, $breadcrumb] = $this->extractEntityJsonLdPair($body, 'DefinedTerm');

        $this->assertSame('DefinedTerm', $definedTerm['@type']);
        $this->assertSame(self::FIXTURE_CLUSTER_HEX, $definedTerm['termCode']);
        $this->assertArrayHasKey('description', $definedTerm);
        $this->assertArrayHasKey('dateModified', $definedTerm);

        $ledeText = $this->ledeText($crawler);
        $this->assertSame($ledeText, $definedTerm['description']);

        $this->assertSame('BreadcrumbList', $breadcrumb['@type']);
    }

    public function testDeviceTypePageHasLedeAndStructuredData(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/device-types/'.self::FIXTURE_DEVICE_TYPE_ID);

        $this->assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();
        $this->assertLedeInFirstThirty($body);
        $this->assertHasVisibleTimeElement($crawler);

        [$definedTerm, $breadcrumb] = $this->extractEntityJsonLdPair($body, 'DefinedTerm');

        $this->assertSame('DefinedTerm', $definedTerm['@type']);
        $this->assertArrayHasKey('description', $definedTerm);
        $this->assertArrayHasKey('dateModified', $definedTerm);

        $ledeText = $this->ledeText($crawler);
        $this->assertSame($ledeText, $definedTerm['description']);

        $this->assertSame('BreadcrumbList', $breadcrumb['@type']);
    }

    public function testDashboardEmitsDatasetMarkup(): void
    {
        $this->assertAggregateStatsPageEmitsDataset('/dashboard');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function aggregateStatsPathProvider(): iterable
    {
        yield 'dashboard' => ['/dashboard'];
        yield 'clusters' => ['/clusters'];
        yield 'device-types' => ['/device-types'];
        yield 'coordination' => ['/coordination'];
        yield 'pairings' => ['/pairings'];
        yield 'commissioning' => ['/commissioning'];
        yield 'market' => ['/market'];
        yield 'versions' => ['/versions'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('aggregateStatsPathProvider')]
    public function testAggregateStatsPageEmitsDataset(string $path): void
    {
        $this->assertAggregateStatsPageEmitsDataset($path);
    }

    private function assertAggregateStatsPageEmitsDataset(string $path): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $path);
        $this->assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();
        $dataset = $this->extractJsonLdByType($body, 'Dataset');
        $this->assertNotNull($dataset, "page $path must emit a Dataset JSON-LD block");

        $this->assertArrayHasKey('name', $dataset);
        $this->assertArrayHasKey('description', $dataset);
        $this->assertArrayHasKey('creator', $dataset);
        $this->assertSame('Matter Survey', $dataset['creator']['name']);
        $this->assertArrayHasKey('license', $dataset);
        $this->assertStringContainsString('creativecommons.org', (string) $dataset['license']);
        $this->assertArrayHasKey('temporalCoverage', $dataset);
        $this->assertArrayHasKey('dateModified', $dataset);
    }

    /**
     * Asserts the rendered `.aeo-lede` element is present and appears within
     * the first 30% of the document body. "First 30%" is measured against
     * the inner body markup, not the full HTML — head metadata doesn't count
     * against the lede budget.
     */
    private function assertLedeInFirstThirty(string $body): void
    {
        $this->assertMatchesRegularExpression('/class="aeo-lede"/', $body, 'lede element must be rendered');

        $bodyOpen = strpos($body, '<body');
        $bodyOpen = false === $bodyOpen ? 0 : $bodyOpen;
        $inner = substr($body, $bodyOpen);

        $pos = strpos($inner, 'class="aeo-lede"');
        $this->assertNotFalse($pos, 'lede element must exist after <body>');

        $thirty = (int) (\strlen($inner) * 0.30);
        $this->assertLessThanOrEqual($thirty, $pos, sprintf('lede must appear in first 30%% of body; found at byte %d of %d (limit %d)', $pos, \strlen($inner), $thirty));
    }

    private function assertHasVisibleTimeElement(\Symfony\Component\DomCrawler\Crawler $crawler): void
    {
        $time = $crawler->filter('.aeo-meta time[datetime]')->first();
        $this->assertGreaterThan(0, $time->count(), 'AEO meta block must include a <time datetime> element');
    }

    private function ledeText(\Symfony\Component\DomCrawler\Crawler $crawler): string
    {
        $lede = $crawler->filter('.aeo-lede')->first();
        $this->assertGreaterThan(0, $lede->count(), 'lede element must be present');

        return trim($lede->text());
    }

    /**
     * Returns [entity JSON-LD with the requested schema.org type, breadcrumb
     * JSON-LD] extracted from the response body. Fails the test if either is
     * missing.
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function extractEntityJsonLdPair(string $body, string $entityType): array
    {
        $entity = $this->extractJsonLdByType($body, $entityType);
        $breadcrumb = $this->extractJsonLdByType($body, 'BreadcrumbList');

        $this->assertNotNull($entity, "must contain JSON-LD with @type=$entityType");
        $this->assertNotNull($breadcrumb, 'must contain BreadcrumbList JSON-LD');

        return [$entity, $breadcrumb];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJsonLdByType(string $body, string $type): ?array
    {
        if (!preg_match_all('#<script[^>]*type="application/ld\+json"[^>]*>(.+?)</script>#s', $body, $matches)) {
            return null;
        }

        foreach ($matches[1] as $jsonStr) {
            $parsed = json_decode(trim($jsonStr), true);
            if (\is_array($parsed) && ($parsed['@type'] ?? null) === $type) {
                /* @var array<string, mixed> $parsed */
                return $parsed;
            }
        }

        return null;
    }

    private function assertMatchesIsoDate(string $date): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $date, "dateModified must be ISO-8601 date, got '$date'");
    }
}
