<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Every public page must expose exactly one <h1>; the site name in the
 * header is only promoted to <h1> on pages without their own heading.
 */
final class HeadingStructureTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function pageProvider(): iterable
    {
        foreach (['/', '/vendors', '/dashboard', '/clusters', '/device-types', '/versions', '/pairings', '/about', '/faq', '/glossary', '/matter', '/wizard', '/compare', '/login'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('pageProvider')]
    public function testPageHasExactlyOneH1(string $url): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', $url);

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('h1'), \sprintf('%s should have exactly one <h1>', $url));
    }

    public function testDevicePageHasExactlyOneH1(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');
        $link = $crawler->filter('a[href^="/device/"]')->first();
        $this->assertCount(1, $link);

        $crawler = $client->click($link->link());
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('h1'));
    }
}
