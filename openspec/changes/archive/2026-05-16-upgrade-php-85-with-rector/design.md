## Context

The PHP version pin in this repo has drifted out of sync with reality twice in recent memory: the README still says `PHP 8.1+`, and `CLAUDE.md` says `(PHP 8.4)` while prod has just moved to 8.5. Each bump has been a hand-grep across composer, two workflow files, and the docs — easy to miss one. There's no static enforcement that the `composer.json` floor matches the CI matrix.

Beyond the version drift, the codebase carries an accumulated tail of pre-modern PHP idioms (some readonly-eligible properties, nullable union types that could be `T|null`, constructor patterns that predate property promotion in a handful of places). None of it is broken; none of it has been worth a dedicated cleanup PR. Rector is the standing tool for exactly that class of cleanup — apply mechanical upgrades, in batches the team can review, without anyone having to hand-edit hundreds of small sites.

The hosting environment is the existing constraint (KAS shared host, no installable extensions). Rector is a pure-PHP dev-dependency tool that runs in CI and developer environments — it never executes on the production host, so the shared-host constraint doesn't bind.

## Goals / Non-Goals

**Goals:**

- Single source of truth: `composer.json` declares the PHP floor, and CI / docs follow it.
- CI runs the same PHP version that production runs.
- Establish Rector as the project's automated-upgrade tool with a minimal, defensible initial configuration.
- Make the bisect history of this change clean: constraint bump and the resulting Rector diff are separate commits.
- Make Rector part of the standard pre-commit reflex (`make lint`, `make analyse`, `make rector`).

**Non-Goals:**

- Migrating PHPStan or php-cs-fixer into `tools/<tool>/`. They work fine in the root lock today, and moving them is churn for zero immediate benefit. The convention is established here so they can migrate one at a time if pain emerges.
- Adopting a Composer plugin (e.g. `bamarni/composer-bin-plugin`) or PHIVE to automate per-tool installs. One manual `composer install -d tools/rector` is transparent enough at this scale; revisit if the `tools/` directory grows past two or three occupants.
- Upgrading Symfony 7.4 → 8.0 (composer reports them available). Symfony 7.4 already supports PHP 8.5; bundling the framework bump multiplies the surface under review for no gain to this change. Rector's `withComposerBased(symfony: true)` only applies rules matching the *installed* Symfony version, so Symfony 7.4 stays in place.
- Enabling Rector's `naming`, `codingStyle`, `typeDeclarationDocblocks`, `carbon`, or `rectorPreset` prepared sets — see Decision 2 for the per-set rationale.
- Adopting specific 8.5 syntax features (pipe operator, `array_first()/array_last()`, `#[\NoDiscard]`) by hand. Anything Rector emits, fine. Hand-pulling features into the diff invites scope creep.
- Adding caching for the Rector CI step. Project is small; a cold run is fast enough to not warrant the cache-key complexity yet.

## Decisions

### Decision 1: One commit for the bump, one commit for the Rector diff

The constraint change (composer, CI, docs, Makefile, `rector.php`) is mechanical, near-zero-risk, and reviewable as a config diff. The Rector output is a code refactor across an unknown number of files. Bundling them into one commit makes the version bump effectively unrevertible without also reverting the refactor, and obscures what "broke" if a downstream change later regresses.

Two commits, in this order:

1. `chore: require PHP 8.5 and introduce Rector` — composer, CI, docs, Makefile, `rector.php`, Rector dev-dep.
2. `refactor: apply Rector PHP 8.5 ruleset` — only the files Rector rewrote.

**Alternative considered:** single squashed commit. Rejected — loses the bisectability between "did the constraint bump break us" and "did the refactor break us".

### Decision 2: Rector config combines three layers — `withPhpSets`, `withComposerBased`, and curated `withPreparedSets`

Rector 2.x exposes a fluent builder API. The full configuration is three method calls, each doing a distinct job:

```php
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withPhpSets()                          // PHP-version upgrades, auto-matched to composer.json
    ->withComposerBased(                     // framework upgrades, auto-matched to installed versions
        symfony:  true,
        doctrine: true,
        phpunit:  true,
        twig:     true,
    )
    ->withPreparedSets(                      // curated quality rule categories — see table below
        codeQuality:          true,
        deadCode:             true,
        typeDeclarations:     true,
        privatization:        true,
        earlyReturn:          true,
        instanceOf:           true,
        symfonyCodeQuality:   true,
        doctrineCodeQuality:  true,
        phpunitCodeQuality:   true,
    );
```

`withComposerBased(...)` is the key piece. It inspects the installed versions of `symfony/*`, `doctrine/*`, `phpunit/phpunit`, and `twig/twig` in `composer.lock` and applies only the upgrade rules matching those versions. No hand-rolled `SymfonySetList::SYMFONY_74` enumeration; no risk of accidentally running rules meant for a Symfony major we're not on. When the framework moves later, the Rector config follows automatically.

The prepared-set curation is opinionated. Here's the full list of available toggles and the call for each one:

| Set | Decision | Why |
|---|---|---|
| `codeQuality` | **enable** | Mechanical simplifications (`isset()` → `??`, redundant casts, etc.). High signal, low risk. |
| `deadCode` | **enable** | Removes genuinely unreachable code. Risk: kills code only called by DI or reflection — caught by the test suite + a focused review of the diff. |
| `typeDeclarations` | **enable** | Adds missing param/return types. Project is already largely typed; this fills remaining gaps. |
| `privatization` | **enable** | Tightens visibility where members are only used locally. Pure encapsulation win. |
| `earlyReturn` | **enable** | Flattens nested conditionals. Pure readability. |
| `instanceOf` | **enable** | Modernizes `is_a()` and similar patterns to `instanceof`. |
| `symfonyCodeQuality` | **enable** | Symfony-aware quality (proper controller patterns, service config idioms, etc.). |
| `doctrineCodeQuality` | **enable** | Doctrine-aware quality (entity patterns, query builder idioms). |
| `phpunitCodeQuality` | **enable** | PHPUnit-aware quality (assertion specificity, setUp patterns). |
| `codingStyle` | **skip** | Overlaps with `php-cs-fixer @Symfony`, the single source of style truth. Two style enforcers will fight or one will defer; better to have one. |
| `naming` | **skip** | Renames methods/properties. High blast radius, low review confidence, can break BC for any code that touches the renamed symbols. Adopt only via targeted review later, never as a blanket set. |
| `typeDeclarationDocblocks` | **skip** | Adds `@param`/`@return` docblocks. This codebase prefers real types over docblocks (PHPStan reads from native types). Docblock additions are noise without signal here. |
| `carbon` | **skip** | Migrates `DateTime` usage to Carbon. Carbon isn't a dependency; not applicable. |
| `rectorPreset` | **skip** | Rector's own opinionated meta-preset. Adopt later if specific rules in it become attractive; don't take it wholesale. |

The first run will produce a substantially larger diff than a PHP-only run would have — that's the explicit intent. We're spending the review budget once, broadly, rather than dripping it out across many follow-up "apply Rector set X" proposals. The two-commit structure (Decision 1) and the per-set rationale above are what make that diff reviewable.

**Alternative considered:** PHP set only, then follow-up proposals for each framework / quality set. Rejected — produces five separate PRs over time, each with its own review and CI cost, for a benefit (smaller individual diffs) that's only really useful if a specific set turns out to be controversial. If a single set does turn out to be problematic during this change's review, we can disable it in `rector.php` and reroll the refactor commit — cheap to undo at this stage.

**Alternative considered:** `PhpLevelSetList::PHP_85` only (legacy explicit form). Rejected — the modern `withPhpSets()` is cumulative *and* auto-tracks the composer floor, so it's both broader and self-maintaining.

### Decision 3: PHPStan `phpVersion: 80500`

Pinning PHPStan to the PHP version it analyzes for is a small but real correctness win — it catches uses of removed/changed APIs (e.g. anything removed in 8.5, attribute resolution differences). Without it, PHPStan analyzes for whatever the local PHP minor happens to be, which is unstable across contributor environments.

This will likely surface a handful of new findings on top of the existing baseline. Policy: resolve in-band where trivial, append to `phpstan-baseline.neon` where not. **Do not lower the level** to make findings disappear.

### Decision 4: Rector lives under `tools/rector/` with its own composer.json and lockfile

Adding `rector/rector` to the root `require-dev` is the easiest path, but it has two real costs that get worse the more dev tools we accumulate:

1. **Transitive bloat in the app lock.** Rector pulls in `phpstan/phpstan-src`, `symplify/*` helpers, and dozens of transitive packages. None of these are needed at runtime, none participate in the app's dependency graph, but they all sit in `composer.lock` and have to be re-resolved every time we touch a real app dep.
2. **Version-resolution interference.** Tools that depend on Symfony components (Rector indirectly does, php-cs-fixer directly does) can constrain what versions of `symfony/console` etc. Composer picks for the *app*. Currently invisible, but the failure mode is "I bumped Rector and Composer downgraded my Symfony" — exactly the kind of action-at-a-distance that's hard to debug.

The mitigation is `tools/<tool>/composer.json` per tool, each with its own lockfile and isolated `vendor/`. Rector installs to `tools/rector/vendor/bin/rector` and is invoked from there. Its dependency graph is entirely disjoint from the app's.

Concrete layout:

```
tools/rector/
  composer.json          → require-dev: { "rector/rector": "^2.0" }
  composer.lock          → committed (deterministic installs in CI)
  .gitignore             → vendor/
```

Layout, **not** migration: this change *introduces* the convention but does not move existing dev tools. PHPStan and php-cs-fixer stay in the root `require-dev` for now. Migrating them is a separate, value-neutral churn unless and until specific conflicts surface. The convention being in place means a future migration is "move the require, regenerate the lock," not "design a new convention".

**Alternative considered:** `bamarni/composer-bin-plugin` to automate the per-tool install workflow. It works (`composer bin rector require rector/rector`, `composer bin all update`, etc.), but it's one more Composer plugin to allow, one more behavior to document, and an indirection for a workflow that's currently two manual commands. Adopt it later if the `tools/` directory grows past two or three occupants and the manual cadence starts hurting.

**Alternative considered:** PHIVE (PHARs as the tool-distribution layer). Heavier setup, requires PHARs to be available for every tool we care about, and breaks the developer ergonomic of "everything is a composer install". Out.

**Alternative considered:** Keep Rector in root `require-dev`, defer the `tools/` convention to a separate proposal. Rejected — the moment to establish a convention is when you have a real, motivating example. Doing this now is one commit; doing it later means a "move Rector" PR with no other content.

### Decision 5: Rector runs in the existing `code-quality` job, not a new job

The `code-quality` job already does `composer validate`, `composer audit`, `php-cs-fixer`, and `phpstan`. Rector belongs in the same conceptual bucket — a static check that the codebase is in a canonical state. A dedicated `rector` job would duplicate the PHP/composer setup for one command. Slot it between `php-cs-fixer` and `phpstan`.

The job already runs `composer install` for the app. The Rector step adds one more install line — `composer install -d tools/rector --no-interaction --no-progress` — and then runs `tools/rector/vendor/bin/rector process --dry-run`. Exit code is non-zero on any pending change, matching the `php-cs-fixer --dry-run` pattern already in the workflow.

### Decision 6: `rector.php` scope is `src/` and `tests/`

Same scope as PHPStan, for the same reason: those are the files we own and want kept canonical. Excluding `tests/` would mean test code drifts to an older idiom over time. Including it accepts that some Rector transforms (e.g. type narrowing on test helpers) may produce test diffs alongside production code.

`var/`, `vendor/`, `tools/`, `public/bundles/`, `config/bundles.php`, and `config/reference.php` are excluded for the same reasons they're excluded from php-cs-fixer (plus `tools/` to avoid Rector trying to refactor its own dependency tree).

### Decision 7: No Rector cache configuration yet

Rector supports a cache directory for incremental runs. For a project this size, the speedup is in the seconds-range and the cache-invalidation footguns (stale cache hiding real findings, cache dir bloat in CI) outweigh the win. Add caching only if CI wall time becomes a real friction point.

## Risks and Open Questions

- **8.5 deprecations from the dependency tree** — the CI run on the constraint-bump commit is the trip-wire. If Symfony 7.4 / Doctrine ORM 3 / OTel SDK 1.14 throw deprecations under 8.5, they show up in the test output here, not in a quiet log file in prod next week. Acceptance criterion: zero new deprecations from our code, dependency deprecations triaged (resolve, suppress with reason, or upstream-issue) before merge.

- **`phpVersion: 80500` baseline churn** — depends entirely on what's in the current `phpstan-baseline.neon` and whether 8.5-specific findings light up. If the diff there balloons, it's still acceptable for this change so long as the level stays at 6.

- **Rector first-pass diff size** — unknown until run. The broad ruleset (PHP + framework + nine prepared sets) is expected to produce a large diff. The mitigation is *per-set* rollback, not whole-tool rollback: if any single set produces controversial or non-mechanical changes during review, disable that one flag in `rector.php`, reroll the refactor commit, and proceed. The two-commit structure makes this cheap.

- **`deadCode` removing DI- or reflection-called code** — the most likely false positive. Symfony service tags, event subscriber `getSubscribedEvents()` entries, command attributes, and Doctrine event listeners can all *look* unreferenced to a static analyzer. The test suite is the primary safety net; the secondary check is a focused human read of the refactor commit's deletions before merge. If a real removal slips past both, disable `deadCode` and reroll.

- **`open-telemetry/sdk` reflection paths** — the OTel SDK does some reflection-heavy work for instrumentation. Running `app:otel:doctor` and the full PHPUnit suite under 8.5 is the smoke test; cover both in the validation step.

- **Future tooling work** — out of scope here, but the natural follow-ups are: migrate PHPStan and php-cs-fixer into `tools/<tool>/`; consider `bamarni/composer-bin-plugin` once the `tools/` directory has three or more occupants; revisit the skipped prepared sets (`naming`, `codingStyle`, `typeDeclarationDocblocks`, `rectorPreset`) if specific use cases emerge. Worth noting so future-us doesn't reinvent the rationale.
