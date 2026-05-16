<?php

declare(strict_types=1);

namespace App\Observability\Bootstrap;

use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Logs\LoggerProviderFactory;
use OpenTelemetry\SDK\Metrics\MeterProviderFactory;
use OpenTelemetry\SDK\Propagation\PropagatorFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\TracerProviderFactory;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Builds OpenTelemetry providers from environment configuration and registers
 * them as the global SDK once per process. Idempotent and short-circuits when
 * disabled via OTEL_SDK_DISABLED.
 *
 * Subscribes to KernelEvents::REQUEST and ConsoleEvents::COMMAND so that both
 * HTTP and CLI entrypoints initialise the SDK before any user code runs.
 */
final class OtelBootstrap implements EventSubscriberInterface
{
    private bool $booted = false;

    public function __construct(
        private readonly string $serviceName,
        private readonly string $serviceVersion,
        private readonly string $environment,
        private readonly bool $disabled,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['boot', 1024]],
            ConsoleEvents::COMMAND => [['boot', 1024]],
        ];
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        if ($this->disabled) {
            return;
        }

        $this->ensureEnv('OTEL_SERVICE_NAME', $this->serviceName);
        $this->mergeResourceAttributes([
            'service.version' => $this->serviceVersion,
            'deployment.environment.name' => $this->environment,
        ]);

        $tracerProvider = new TracerProviderFactory()->create();
        $meterProvider = new MeterProviderFactory()->create();
        $loggerProvider = new LoggerProviderFactory()->create();
        $propagator = new PropagatorFactory()->create();

        Sdk::builder()
            ->setTracerProvider($tracerProvider)
            ->setMeterProvider($meterProvider)
            ->setLoggerProvider($loggerProvider)
            ->setPropagator($propagator)
            ->setAutoShutdown(true)
            ->buildAndRegisterGlobal();
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Allows tests to re-run boot() inside a fresh process simulation by
     * resetting the booted flag and tearing down any previously-registered
     * global providers.
     */
    public function reset(): void
    {
        $this->booted = false;
        Globals::reset();
    }

    private function ensureEnv(string $name, string $value): void
    {
        if (false !== getenv($name) && '' !== getenv($name)) {
            return;
        }
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /**
     * @param array<string, string> $additions
     */
    private function mergeResourceAttributes(array $additions): void
    {
        $existing = getenv('OTEL_RESOURCE_ATTRIBUTES');
        $existing = false === $existing ? '' : $existing;

        $missing = [];
        foreach ($additions as $key => $value) {
            if (!str_contains($existing, $key.'=')) {
                $missing[] = $key.'='.$value;
            }
        }

        if ([] === $missing) {
            return;
        }

        $merged = '' === $existing ? implode(',', $missing) : $existing.','.implode(',', $missing);
        putenv('OTEL_RESOURCE_ATTRIBUTES='.$merged);
        $_ENV['OTEL_RESOURCE_ATTRIBUTES'] = $merged;
        $_SERVER['OTEL_RESOURCE_ATTRIBUTES'] = $merged;
    }
}
