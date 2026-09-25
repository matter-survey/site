<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LocalizedTitlesTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function titleProvider(): iterable
    {
        yield 'vendors en' => ['/vendors', 'Vendors - Matter Survey'];
        yield 'vendors de' => ['/de/vendors', 'Hersteller - Matter Survey'];
        yield 'clusters de' => ['/de/clusters', 'Cluster-Statistiken - Matter Survey'];
        yield 'dashboard en' => ['/dashboard', 'Dashboard - Matter Survey'];
        yield 'coordination de' => ['/de/coordination', 'Koordinationsfunktionen - Matter Survey'];
    }

    #[DataProvider('titleProvider')]
    public function testPageTitleIsLocalized(string $url, string $expectedTitle): void
    {
        $client = self::createClient();
        $client->request('GET', $url);

        $this->assertResponseIsSuccessful();
        $this->assertPageTitleSame($expectedTitle);
    }

    public function testGermanVendorIndexIsTranslated(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/de/vendors');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.page-header h1', 'Matter-Hersteller');
        $this->assertSame('Hersteller suchen...', $crawler->filter('#vendor-search')->attr('placeholder'));
    }
}
