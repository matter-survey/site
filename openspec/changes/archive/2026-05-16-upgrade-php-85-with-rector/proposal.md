## Why

Production was upgraded to PHP 8.5 (KAS/all-inkl), so the project's `^8.4` constraint is now under-tightening: development and CI still run against an older minimum than what actually ships. The codebase also has no automated upgrade tooling, which means every future PHP/Symfony bump becomes a hand-grep exercise across `src/` and `tests/`.

This change raises the floor to PHP 8.5 across composer, CI, and docs, and introduces Rector as the standing tool for mechanical upgrades — bootstrapped with the PHP 8.5 ruleset plus framework-aware (Symfony, Doctrine, PHPUnit, Twig) and curated quality sets, so the codebase actually adopts modern idioms across the stack rather than just being _allowed_ to use them. To keep dev-tool dependencies from polluting the app's `composer.json`, Rector is installed under a new `tools/<tool>/` convention with its own composer manifest and lockfile.

## What Changes

- Bump `composer.json` PHP requirement from `^8.4` to `^8.5`; regenerate `composer.lock`.
- Update both GitHub Actions workflows (`ci.yml`, `matter-sync.yml`) to `php-version: '8.5'`.
- Update `CLAUDE.md` and `README.md` to reflect the 8.5 floor (and fix the already-stale `PHP 8.1+` line in the README).
- Add `phpVersion: 80500` to `phpstan.dist.neon` so static analysis is 8.5-aware (this can surface new findings; resolve them or add to baseline as part of this change).
- Introduce a `tools/<tool>/composer.json` convention for dev-only tooling, so the main app `composer.json` doesn't accumulate dev-tool transitive dependencies. Rector is the first inhabitant; PHPStan and php-cs-fixer stay in the root `require-dev` for now and can migrate independently later if conflicts or upgrade friction emerge.
- Add `tools/rector/composer.json` requiring `rector/rector` ^2.x as a `require-dev`, with its own committed `tools/rector/composer.lock` and a `tools/rector/.gitignore` that ignores `vendor/` (single package — Symfony, Doctrine, PHPUnit, and Twig rule sets ship inside core in Rector 2.x).
- Add a `rector.php` config at the repo root (Rector resolves paths from CWD; the binary under `tools/rector/vendor/bin/` reads from there) scoped to `src/` and `tests/` that combines three layers of rules:
  - **`->withPhpSets()`** — PHP-version upgrade rules, auto-matched to the composer floor (now 8.5).
  - **`->withComposerBased(symfony: true, doctrine: true, phpunit: true, twig: true)`** — framework upgrade rules, auto-matched to the installed versions of `symfony/*`, `doctrine/*`, `phpunit/phpunit`, and `twig/twig`.
  - **`->withPreparedSets(...)`** — curated quality sets: `codeQuality`, `deadCode`, `typeDeclarations`, `privatization`, `earlyReturn`, `instanceOf`, `symfonyCodeQuality`, `doctrineCodeQuality`, `phpunitCodeQuality`. See `design.md` for which prepared sets are deliberately excluded and why.
- Apply Rector in **two commits** so the bisect history is clean:
  1. The config commit (constraint bump + `rector.php` + Makefile + CI step + dev-dep). Mechanical, near-zero risk.
  2. The refactor commit — only the files Rector rewrites. Reviewable as a pure refactor.
- Add `make rector` (dry-run) and `make rector-fix` (apply) targets, mirroring the existing `lint` / `lint-fix` pattern. Both targets run `composer install -d tools/rector --no-interaction --quiet` first so the tool is available before invocation.
- Add a Rector dry-run step to the `code-quality` job in `.github/workflows/ci.yml`, between the existing `php-cs-fixer` and `phpstan` steps. The step `composer install`s the tool from `tools/rector/` (separate from the main app install above it), then runs `tools/rector/vendor/bin/rector process --dry-run`. The build fails on any pending Rector change, same posture as `php-cs-fixer`.

## Capabilities

### New Capabilities

<!-- None — this is a runtime/tooling change with no user-facing surface. -->

### Modified Capabilities

<!-- None — no existing capability spec is affected. -->

## Impact

- **Code touched**: `composer.json`, `composer.lock`, `.github/workflows/ci.yml`, `.github/workflows/matter-sync.yml`, `phpstan.dist.neon`, `Makefile`, `CLAUDE.md`, `README.md`, new files `rector.php`, `tools/rector/composer.json`, `tools/rector/composer.lock`, `tools/rector/.gitignore`, plus whatever Rector rewrites under `src/` and `tests/` on the first pass.
- **Runtime risk**: PHP 8.5 is already live in prod, so this change closes a gap rather than opening one. CI moves from "tests on 8.4" to "tests on 8.5" — any 8.5 deprecations in the dependency tree (Symfony 7.4, Doctrine, OpenTelemetry SDK) will surface here, on the CI run for this change, rather than silently in production.
- **PHPStan baseline**: the `phpVersion: 80500` pin may surface new findings on top of the existing baseline. Resolve them in-band or add to `phpstan-baseline.neon` — no level downgrade.
- **CI duration**: one additional `composer install -d tools/rector` plus `tools/rector/vendor/bin/rector process --dry-run` on every code-quality run. Project is small; expected impact a handful of seconds (Rector's install graph is moderate, and Composer is fast against the GitHub Actions runner's network).
- **Developer workflow**: contributors now have one more "before you commit" step (`make rector` alongside `make lint` and `make analyse`). Documented in `CLAUDE.md` build commands section.
- **Reversibility**: high. Composer constraint and CI pins revert in one commit. The Rector cleanup commit is a normal refactor diff and revertible like any other.
