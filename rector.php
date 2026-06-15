<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/config/bundles.php',
        __DIR__.'/config/reference.php',
        __DIR__.'/public/bundles',
        __DIR__.'/tools',
        __DIR__.'/var',
        __DIR__.'/vendor',
        // Converting `static fn (string $a, string $b): int => version_compare($a, $b)`
        // into the first-class callable `version_compare(...)` reintroduces a
        // PHPStan level-7 error: version_compare's 3-arg form returns int|bool,
        // which does not satisfy the callable(string, string): int that
        // usort()/uksort() expect. The explicit closure is intentional.
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class,
    ])
    ->withPhpSets()
    ->withComposerBased(
        symfony: true,
        doctrine: true,
        phpunit: true,
        twig: true,
    )
    ->withPreparedSets(
        codeQuality: true,
        deadCode: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        instanceOf: true,
        symfonyCodeQuality: true,
        doctrineCodeQuality: true,
        phpunitCodeQuality: true,
    );
