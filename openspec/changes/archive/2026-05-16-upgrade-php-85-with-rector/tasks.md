## 1. PHP version floor

- [ ] 1.1 Bump `composer.json` `require.php` from `^8.4` to `^8.5`
- [ ] 1.2 Run `composer update --lock` (or `composer update` if dependency churn is acceptable) and review the resulting `composer.lock` diff
- [ ] 1.3 Update `.github/workflows/ci.yml`: change both `php-version: '8.4'` pins (in `test` matrix and `code-quality` job) to `'8.5'`
- [ ] 1.4 Update `.github/workflows/matter-sync.yml`: change `php-version: '8.4'` to `'8.5'`

## 2. Rector tooling layout

- [ ] 2.1 Create `tools/rector/composer.json` with `require-dev: { "rector/rector": "^2.0" }`, `config.sort-packages: true`, and no `name` or `description` (it's a tool sandbox, not a publishable package)
- [ ] 2.2 Run `composer install -d tools/rector --no-interaction` to generate `tools/rector/composer.lock`
- [ ] 2.3 Create `tools/rector/.gitignore` containing `vendor/`
- [ ] 2.4 Commit `tools/rector/composer.json` and `tools/rector/composer.lock`; verify `tools/rector/vendor/` is not staged

## 3. Rector configuration

- [ ] 3.1 Create `rector.php` at the repo root with the three-layer setup from Decision 2: `withPhpSets()` + `withComposerBased(symfony: true, doctrine: true, phpunit: true, twig: true)` + the curated `withPreparedSets(...)` toggles (`codeQuality`, `deadCode`, `typeDeclarations`, `privatization`, `earlyReturn`, `instanceOf`, `symfonyCodeQuality`, `doctrineCodeQuality`, `phpunitCodeQuality`)
- [ ] 3.2 Set paths to `[__DIR__ . '/src', __DIR__ . '/tests']`
- [ ] 3.3 Add `skip` entries for `var/`, `vendor/`, `tools/`, `public/bundles/`, `config/bundles.php`, and `config/reference.php`
- [ ] 3.4 Verify the config parses: `tools/rector/vendor/bin/rector list --config=rector.php` exits 0

## 4. Make and CI wiring

- [ ] 4.1 Add `make rector` target: runs `composer install -d tools/rector --no-interaction --quiet` then `tools/rector/vendor/bin/rector process --dry-run --config=rector.php`
- [ ] 4.2 Add `make rector-fix` target: same install, then `tools/rector/vendor/bin/rector process --config=rector.php`
- [ ] 4.3 Add both targets to the `.PHONY` declaration and the `help` target's listing
- [ ] 4.4 In `.github/workflows/ci.yml` `code-quality` job, insert a Rector step between `php-cs-fixer` and `phpstan`: first `composer install -d tools/rector --no-interaction --no-progress`, then `tools/rector/vendor/bin/rector process --dry-run`

## 5. PHPStan 8.5 awareness

- [ ] 5.1 Add `phpVersion: 80500` under `parameters:` in `phpstan.dist.neon`
- [ ] 5.2 Run `make analyse` locally on the unmodified codebase; if new findings appear, resolve trivial ones in-band and add structural ones to `phpstan-baseline.neon`
- [ ] 5.3 Confirm `level: 6` is unchanged

## 6. Documentation

- [ ] 6.1 Update `CLAUDE.md` line 43 (`Symfony 7.3 with MicroKernel (PHP 8.4)` → `Symfony 7.4 with MicroKernel (PHP 8.5)`; also fix the stale Symfony version while here)
- [ ] 6.2 Update `CLAUDE.md` line 145 (`PHPUnit on PHP 8.4` → `PHPUnit on PHP 8.5`)
- [ ] 6.3 Add a paragraph under "Build & Development Commands" describing `make rector` / `make rector-fix` and noting that Rector lives under `tools/rector/` with its own composer install
- [ ] 6.4 Add a short "Tooling layout" subsection (or extend an existing architecture section) explaining the `tools/<tool>/composer.json` convention, why Rector uses it, and that PHPStan / php-cs-fixer remain in the root `require-dev` for now
- [ ] 6.5 Update `README.md` line 66 (`PHP 8.1+` → `PHP 8.5+`)

## 7. First commit — config only

- [ ] 7.1 Stage everything except Rector-generated code changes under `src/` and `tests/`
- [ ] 7.2 Commit as `chore: require PHP 8.5 and introduce Rector under tools/`
- [ ] 7.3 Push the commit and confirm CI passes on the constraint-only change: tests run on 8.5, code-quality job runs Rector in dry-run mode and finds pending changes (expected — CI will fail here on purpose at this point, *unless* the dry-run is conditionally skipped for the bootstrap commit; alternative: do step 7.3 after step 8 so CI is only ever run on a clean state)

> **Note:** the cleanest sequencing is to land commit 1 and commit 2 together in a single PR, so CI only ever evaluates the combined state. Pushing commit 1 alone will fail the new Rector dry-run step. Keep the PR uncombined for review readability but don't expect CI green on commit 1 in isolation.

## 8. Second commit — applied Rector refactor

- [ ] 8.1 Run `make rector-fix` locally
- [ ] 8.2 Review the resulting diff: a focused read of every deletion (looking for false-positive `deadCode` removals of DI-tagged / reflection-called code), spot-check the type-declaration additions, scan for any signature change to a public method
- [ ] 8.3 If any single prepared set is producing controversial output: disable that flag in `rector.php`, rerun `make rector-fix`, redo the review
- [ ] 8.4 Stage only files Rector rewrote; commit as `refactor: apply Rector PHP 8.5 + framework + quality sets`
- [ ] 8.5 Run `make lint-fix` after Rector — Rector and php-cs-fixer occasionally disagree on whitespace / import ordering; resolve in php-cs-fixer's favor (the established style authority) and amend or follow-up commit the result

## 9. Validation

- [ ] 9.1 Run `make lint` — passes
- [ ] 9.2 Run `make analyse` — passes (no new findings beyond what's in `phpstan-baseline.neon`)
- [ ] 9.3 Run `make rector` — exits 0 (no further pending changes)
- [ ] 9.4 Run the full PHPUnit suite (`php bin/phpunit`) — passes with zero new deprecations from app code; triage any dependency-emitted deprecations under 8.5
- [ ] 9.5 Run `php bin/console app:otel:doctor` locally with `OTEL_SDK_DISABLED=false` and a stub OTLP endpoint — confirm the OTel SDK's reflection paths don't choke on 8.5
- [ ] 9.6 Open PR; verify CI green on the merge commit (test + code-quality + deploy gates)
- [ ] 9.7 After merge to `main`, monitor production deploy logs for runtime deprecations or class-resolution errors that didn't surface in CI
