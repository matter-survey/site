<?php

declare(strict_types=1);

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
