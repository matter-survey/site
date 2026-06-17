<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Single source of the release version, consumed by BOTH the backend OTel
 * resource (service.version) and the frontend Faro app.version meta tag.
 *
 * config/version.php is generated at deploy time (`git describe`, see the
 * Makefile `deploy` target) and rsynced to the server. It is absent in
 * dev/test/CI, where the version falls back to the OTEL_SERVICE_VERSION
 * default ('dev'). Loaded before twig.yaml/services.yaml (alphabetical), so
 * the `app.version` parameter is available to both.
 */
return static function (ContainerConfigurator $container): void {
    $versionFile = \dirname(__DIR__).'/version.php';
    $version = is_file($versionFile) ? trim((string) require $versionFile) : '';

    if ('' === $version) {
        $version = $_SERVER['OTEL_SERVICE_VERSION'] ?? $_ENV['OTEL_SERVICE_VERSION'] ?? 'dev';
    }

    $container->parameters()->set('app.version', $version);
};
