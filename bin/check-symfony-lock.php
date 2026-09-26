<?php

declare(strict_types=1);

/*
 * Fail when composer.lock holds Symfony core components outside the version
 * pinned by extra.symfony.require.
 *
 * That constraint is only enforced by the Flex plugin at resolve time. Updates
 * that run without it (e.g. Dependabot) are free to pick any version a
 * transitive "^7.4|^8.0" allows, which previously left the app on a mix of
 * 7.4, 8.0 and 8.1 components.
 *
 * Core components are released in lockstep with majors >= 5; independently
 * versioned symfony/* packages (flex, ux-*, stimulus-bundle, maker-bundle,
 * monolog-bundle, polyfills, contracts) are all below that and are skipped.
 */

$root = dirname(__DIR__);
$composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
$lock = json_decode((string) file_get_contents($root.'/composer.lock'), true, flags: \JSON_THROW_ON_ERROR);

$required = $composer['extra']['symfony']['require'] ?? null;
if (!is_string($required) || 1 !== preg_match('/^(\d+)\.(\d+)\.\*$/', $required, $m)) {
    fwrite(\STDERR, 'extra.symfony.require must look like "8.1.*", got: '.var_export($required, true)."\n");
    exit(1);
}
$expected = $m[1].'.'.$m[2];

$mismatches = [];
foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
    if (!str_starts_with((string) $package['name'], 'symfony/')) {
        continue;
    }
    if (1 !== preg_match('/^v?(\d+)\.(\d+)\./', (string) $package['version'], $v) || (int) $v[1] < 5) {
        continue;
    }
    if ($v[1].'.'.$v[2] !== $expected) {
        $mismatches[] = sprintf('  %s %s', $package['name'], $package['version']);
    }
}

if ([] !== $mismatches) {
    fwrite(\STDERR, sprintf(
        "composer.lock has Symfony components outside extra.symfony.require (%s):\n%s\n\n".
        "Re-resolve with the Flex plugin enabled: composer update 'symfony/*' --with-all-dependencies\n",
        $required,
        implode("\n", $mismatches),
    ));
    exit(1);
}

echo "All Symfony core components in composer.lock match {$required}.\n";
