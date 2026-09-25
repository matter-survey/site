<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PageStructuredDataTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pageProvider(): iterable
    {
        yield 'faq' => ['/faq', 'FAQPage'];
        yield 'faq de' => ['/de/faq', 'FAQPage'];
        yield 'glossary' => ['/glossary', 'DefinedTermSet'];
        yield 'vendors' => ['/vendors', 'CollectionPage'];
    }

    #[DataProvider('pageProvider')]
    public function testPageEmitsJsonLd(string $url, string $expectedType): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $url);
        $this->assertResponseIsSuccessful();

        $types = [];
        foreach ($crawler->filter('script[type="application/ld+json"]') as $node) {
            $data = json_decode($node->textContent, true, 512, \JSON_THROW_ON_ERROR);
            $this->assertIsArray($data);
            $types[] = $data['@type'] ?? null;
        }

        $this->assertContains($expectedType, $types);
    }

    public function testFaqQuestionsMatchVisibleQuestions(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/faq');

        $visible = $crawler->filter('.faq-question')->each(static fn ($node): string => trim($node->text()));
        $jsonLd = json_decode($crawler->filter('script[type="application/ld+json"]')->first()->text(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($jsonLd);
        $this->assertIsArray($jsonLd['mainEntity']);

        $questions = array_map(static fn (array $q): string => $q['name'], $jsonLd['mainEntity']);
        $this->assertSame($visible, $questions);
    }
}
