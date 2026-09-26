---
name: verify
description: Build, launch and drive the Matter Survey Symfony app locally to verify a change at its real surface (HTTP pages, /api/submit, admin login, browser JS).
---

# Verify recipe (cold-start learnings)

## Setup
```bash
export COMPOSER_ALLOW_SUPERUSER=1
# Container may ship PHP 8.4 while composer.json requires ^8.5:
composer install --no-interaction --ignore-platform-req=php
php bin/console importmap:install      # BEFORE the first HTTP request (see gotchas)
mkdir -p data
php bin/console doctrine:migrations:migrate -n
php bin/console doctrine:fixtures:load --group=matter --append -n
php bin/console app:scores:rebuild
# Fixtures leave products.slug empty -> homepage 500s. Backfill:
php -r 'require "vendor/autoload.php"; $p=new PDO("sqlite:data/matter-survey.db"); $u=$p->prepare("update products set slug=? where id=?"); foreach($p->query("select id,vendor_id,product_id,product_name from products") as $r) $u->execute([App\Entity\Product::generateSlug($r["product_name"],(int)$r["vendor_id"],(int)$r["product_id"]),$r["id"]]);'
php -S 127.0.0.1:8765 -t public public/router.php &
```

## Flows worth driving
- Pages: `/ /de/ /vendors /vendor/{slug} /device/{slug} /dashboard /clusters /cluster/0x0006 /device-types /pairings /faq /glossary /login /health`
- API: `POST /api/submit` (valid v3 payload, empty body, bad JSON, bad UUID); 11th POST within a minute -> 429.
- Admin: `printf 'pw\npw\n' | php bin/console app:user:create you@x.test --admin`, then log in.
  Login uses stateless CSRF: curl needs `-H "Origin: http://127.0.0.1:8765"` (without it -> "Invalid CSRF token").
- Browser: Playwright is global (`require($(npm root -g)/playwright)`), launch with
  `executablePath: '/opt/pw-browsers/chromium'`. Check Turbo nav, chart.js canvases on `/dashboard`, console errors.
- Dependency bumps: `git worktree add <dir> <base>` + same setup on port 8766, then diff page HTML
  (strip nonces/_wdt lines) between the two servers.

## Gotchas
- Hitting a page before `importmap:install` caches an asset map without vendor deps; afterwards pages
  render but `modulepreload` for stimulus/turbo/chart.js/faro is silently missing. `cache:clear` fixes it.
- Cache warmup rewrites `config/reference.php`; `git checkout` it before finishing.
- Rate limiter (10/min, `cache.app`) trips quickly while probing `/api/submit`; reset with
  `php bin/console cache:pool:clear cache.app`.
- Stop the dev server by PID, not `pkill -f 'php -S ...'`: the pattern matches the calling shell.
- `tools/{phpstan,rector}` installs can 403 on api.github.com zipballs in cloud sessions. Workaround:
  `git clone --depth 1 --branch <locked version>` phpstan/phpstan, phpstan/phpstan-symfony and
  rectorphp/rector into the `tools/*/vendor/` paths the configs reference (vendor/ is gitignored).
