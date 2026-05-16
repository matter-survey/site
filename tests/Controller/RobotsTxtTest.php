<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * Smoke test for the per-bot AEO robots.txt (Track 3). Asserts the file is
 * served as text/plain and enumerates each named AI crawler user-agent.
 *
 * Note: `/robots.txt` is a static file under `public/`. With `php -S
 * public/router.php` (dev) or PHP-FPM in prod the static file is served
 * before the front controller is hit. In WebTestCase the kernel does not
 * front-end static files — but for the named-user-agent assertions, this
 * test reads the on-disk file directly. The HTTP-level checks (200,
 * text/plain) are deferred to integration via the dev server.
 */
final class RobotsTxtTest extends KernelTestCase
{
    use HasBrowser;

    private const string ROBOTS_PATH = __DIR__.'/../../public/robots.txt';

    private const array NAMED_USER_AGENTS = [
        'OAI-SearchBot',
        'ChatGPT-User',
        'GPTBot',
        'Claude-SearchBot',
        'Claude-User',
        'ClaudeBot',
        'PerplexityBot',
        'Perplexity-User',
        'Google-Extended',
        'Applebot',
        'Applebot-Extended',
        'Meta-ExternalAgent',
    ];

    public function testRobotsFileExists(): void
    {
        $this->assertFileExists(self::ROBOTS_PATH);
    }

    public function testFirstNonBlankLineIsLastReviewedComment(): void
    {
        $contents = (string) file_get_contents(self::ROBOTS_PATH);
        $lines = preg_split('/\r?\n/', $contents) ?: [];
        $firstNonBlank = array_find($lines, fn ($line): bool => '' !== trim((string) $line));
        $this->assertNotNull($firstNonBlank, 'robots.txt has no non-blank lines');
        $this->assertMatchesRegularExpression('/^# Last reviewed: \d{4}-\d{2}-\d{2}\b/', $firstNonBlank, 'robots.txt must start with a "# Last reviewed: YYYY-MM-DD" comment');
    }

    #[DataProvider('namedUserAgentProvider')]
    public function testNamedUserAgentBlockPresent(string $userAgent): void
    {
        $contents = (string) file_get_contents(self::ROBOTS_PATH);

        $this->assertMatchesRegularExpression('/^User-agent:\s*'.preg_quote($userAgent, '/').'\s*$/m', $contents, "robots.txt missing block for user-agent '$userAgent'");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namedUserAgentProvider(): iterable
    {
        foreach (self::NAMED_USER_AGENTS as $ua) {
            yield $ua => [$ua];
        }
    }

    public function testWildcardFallbackPresent(): void
    {
        $contents = (string) file_get_contents(self::ROBOTS_PATH);

        $this->assertMatchesRegularExpression('/^User-agent:\s*\*\s*$/m', $contents, 'robots.txt must include a wildcard User-agent: * fallback');
    }

    public function testNoDisallowDirectives(): void
    {
        $contents = (string) file_get_contents(self::ROBOTS_PATH);

        $this->assertDoesNotMatchRegularExpression('/^Disallow:/m', $contents, 'robots.txt must not contain any Disallow directives');
    }

    public function testSitemapDirectivePresent(): void
    {
        $contents = (string) file_get_contents(self::ROBOTS_PATH);

        $this->assertMatchesRegularExpression('/^Sitemap:\s+https:\/\/matter-survey\.org\/sitemap\.xml\s*$/m', $contents, 'robots.txt must include the production sitemap directive');
    }

    public function testEachNamedBlockHasAllowSlash(): void
    {
        $contents = (string) file_get_contents(self::ROBOTS_PATH);
        $blocks = $this->parseUserAgentBlocks($contents);

        foreach (self::NAMED_USER_AGENTS as $ua) {
            $this->assertArrayHasKey($ua, $blocks, "missing block for $ua");
            $this->assertContains('Allow: /', $blocks[$ua], "block for $ua must contain 'Allow: /'");
        }
    }

    /**
     * Parse robots.txt into [user-agent => list of directive lines] map.
     * Comments and blank lines split blocks; one block per User-agent header.
     *
     * @return array<string, list<string>>
     */
    private function parseUserAgentBlocks(string $contents): array
    {
        $blocks = [];
        $currentAgent = null;

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $rawLine) {
            $line = trim($rawLine);
            if ('' === $line) {
                continue;
            }
            if (str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^User-agent:\s*(.+)$/i', $line, $m)) {
                $currentAgent = trim($m[1]);
                $blocks[$currentAgent] ??= [];
                continue;
            }
            if (null !== $currentAgent) {
                $blocks[$currentAgent][] = $line;
            }
        }

        return $blocks;
    }
}
