## ADDED Requirements

### Requirement: Explicit per-bot allow rules in robots.txt

The site SHALL serve a `robots.txt` at the document root that contains explicit `User-agent` blocks for each named AI crawler the site supports, in addition to a final wildcard fallback. Each named block SHALL contain at minimum `Allow: /` and no `Disallow` directives. The file SHALL be a static asset at `public/robots.txt`, not a dynamically generated response.

The named user-agents SHALL include all of:

- `OAI-SearchBot`
- `ChatGPT-User`
- `GPTBot`
- `Claude-SearchBot`
- `Claude-User`
- `ClaudeBot`
- `PerplexityBot`
- `Perplexity-User`
- `Google-Extended`
- `Applebot`
- `Applebot-Extended`
- `Meta-ExternalAgent`

#### Scenario: robots.txt is served with text/plain

- **WHEN** an HTTP GET is issued for `/robots.txt`
- **THEN** the response status SHALL be 200
- **AND** the response `Content-Type` header SHALL be `text/plain` (with any charset suffix permitted)

#### Scenario: Each named bot has its own User-agent block

- **WHEN** the served `/robots.txt` is parsed
- **THEN** there SHALL be a `User-agent: <name>` line for each of the twelve named crawlers above
- **AND** the block following each named user-agent SHALL contain `Allow: /`
- **AND** the block following each named user-agent SHALL NOT contain any `Disallow` directive

#### Scenario: Wildcard fallback preserves default access

- **WHEN** the served `/robots.txt` is parsed
- **THEN** it SHALL contain a `User-agent: *` block with `Allow: /` as the final user-agent block before the Sitemap directive
- **AND** no `Disallow` directive SHALL appear anywhere in the file

### Requirement: Sitemap directive preserved

The `robots.txt` SHALL contain a `Sitemap:` directive pointing to the absolute URL of the sitemap index (`https://matter-survey.org/sitemap.xml`). The directive SHALL appear once.

#### Scenario: Sitemap directive points to the index

- **WHEN** the served `/robots.txt` is parsed
- **THEN** it SHALL contain exactly one line beginning with `Sitemap: ` and the URL SHALL match the production sitemap index URL

### Requirement: File header documents last review date

The `robots.txt` SHALL begin with a comment header containing the date the file was last reviewed, in ISO-8601 format (`# Last reviewed: YYYY-MM-DD`). The header MAY also include a brief comment explaining the per-bot allowance policy.

#### Scenario: Header is present and parseable

- **WHEN** the served `/robots.txt` is read
- **THEN** the first non-blank line SHALL be a comment line matching the pattern `# Last reviewed: \d{4}-\d{2}-\d{2}`
