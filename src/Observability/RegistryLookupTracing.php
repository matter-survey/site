<?php

declare(strict_types=1);

namespace App\Observability;

/**
 * Opt-in toggle for `matter_registry.lookup` spans, resolved from
 * `OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED` once per process and cached.
 *
 * When disabled (the default), MatterRegistry hot-path wrappers short-circuit
 * to their `do<Name>` implementations and pay only one boolean read per call.
 */
final class RegistryLookupTracing
{
    private static ?bool $enabled = null;

    public static function enabled(): bool
    {
        return self::$enabled ??= self::resolve();
    }

    /**
     * Reset the cached flag. Test-only — invoked from InMemoryOtelTrait so
     * tests can flip the env var without leaking state across tests.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$enabled = null;
    }

    private static function resolve(): bool
    {
        $value = $_SERVER['OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED']
            ?? $_ENV['OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED']
            ?? getenv('OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED');

        if (false === $value || '' === $value) {
            return false;
        }

        return \in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
