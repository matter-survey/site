<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\Bootstrap\OtelBootstrap;
use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

final class OtelBootstrapTest extends TestCase
{
    private const array ENV_VARS = [
        'OTEL_SDK_DISABLED',
        'OTEL_SERVICE_NAME',
        'OTEL_SERVICE_VERSION',
        'OTEL_RESOURCE_ATTRIBUTES',
        'OTEL_TRACES_EXPORTER',
        'OTEL_METRICS_EXPORTER',
        'OTEL_LOGS_EXPORTER',
        'OTEL_EXPORTER_OTLP_PROTOCOL',
        'OTEL_PHP_TRACES_PROCESSOR',
    ];

    /** @var array<string, array{getenv: string|false, env: ?string, server: ?string}> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        foreach (self::ENV_VARS as $name) {
            $this->envBackup[$name] = [
                'getenv' => getenv($name),
                'env' => $_ENV[$name] ?? null,
                'server' => $_SERVER[$name] ?? null,
            ];
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        Globals::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $backup) {
            if (false === $backup['getenv']) {
                putenv($name);
            } else {
                putenv($name.'='.$backup['getenv']);
            }
            if (null === $backup['env']) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $backup['env'];
            }
            if (null === $backup['server']) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $backup['server'];
            }
        }

        Globals::reset();
    }

    public function testDisabledShortCircuits(): void
    {
        $providerBefore = Globals::tracerProvider();

        $bootstrap = new OtelBootstrap('matter-survey', 'matter-survey', 'dev', 'test', disabled: true);
        $bootstrap->boot();

        $this->assertTrue($bootstrap->isBooted());
        $this->assertSame($providerBefore, Globals::tracerProvider(), 'Disabled bootstrap must not register a new global provider');
    }

    public function testEnabledRegistersRealProvider(): void
    {
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_METRICS_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        putenv('OTEL_PHP_TRACES_PROCESSOR=simple');

        $bootstrap = new OtelBootstrap('matter-survey', 'matter-survey', '1.2.3', 'prod', disabled: false);
        $bootstrap->boot();

        $this->assertTrue($bootstrap->isBooted());
        $this->assertInstanceOf(TracerProvider::class, Globals::tracerProvider());

        $this->assertSame('matter-survey', getenv('OTEL_SERVICE_NAME'));
        $this->assertStringContainsString('service.version=1.2.3', (string) getenv('OTEL_RESOURCE_ATTRIBUTES'));
        $this->assertStringContainsString('deployment.environment.name=prod', (string) getenv('OTEL_RESOURCE_ATTRIBUTES'));
    }

    public function testBootIsIdempotent(): void
    {
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_METRICS_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        putenv('OTEL_PHP_TRACES_PROCESSOR=simple');

        $bootstrap = new OtelBootstrap('matter-survey', 'matter-survey', '1.0.0', 'test', disabled: false);
        $bootstrap->boot();
        $first = Globals::tracerProvider();
        $bootstrap->boot();

        $this->assertSame($first, Globals::tracerProvider());
    }

    public function testExistingResourceAttributesArePreserved(): void
    {
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_METRICS_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        putenv('OTEL_PHP_TRACES_PROCESSOR=simple');
        putenv('OTEL_RESOURCE_ATTRIBUTES=service.version=9.9.9,custom.tag=keep');

        $bootstrap = new OtelBootstrap('matter-survey', 'matter-survey', '1.0.0', 'prod', disabled: false);
        $bootstrap->boot();

        $resolved = (string) getenv('OTEL_RESOURCE_ATTRIBUTES');
        $this->assertStringContainsString('service.version=9.9.9', $resolved);
        $this->assertStringContainsString('custom.tag=keep', $resolved);
        $this->assertStringContainsString('deployment.environment.name=prod', $resolved);
    }

    /**
     * Regression: Symfony Dotenv writes to $_SERVER/$_ENV but not getenv().
     * The bootstrap must read existing attributes from those sources so an
     * operator-provided service.namespace survives the boot-time merge.
     */
    public function testServerProvidedResourceAttributesArePreserved(): void
    {
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_METRICS_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        putenv('OTEL_PHP_TRACES_PROCESSOR=simple');
        // Set ONLY via $_SERVER (the Dotenv path), not putenv().
        $_SERVER['OTEL_RESOURCE_ATTRIBUTES'] = 'service.namespace=matter-survey';

        $bootstrap = new OtelBootstrap('site', 'matter-survey', '1.0.0', 'prod', disabled: false);
        $bootstrap->boot();

        $resolved = (string) getenv('OTEL_RESOURCE_ATTRIBUTES');
        $this->assertStringContainsString('service.namespace=matter-survey', $resolved);
        $this->assertStringContainsString('service.version=1.0.0', $resolved);
        $this->assertStringContainsString('deployment.environment.name=prod', $resolved);
    }

    public function testServiceNamespaceDefaultIsApplied(): void
    {
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_METRICS_EXPORTER=none');
        putenv('OTEL_LOGS_EXPORTER=none');
        putenv('OTEL_PHP_TRACES_PROCESSOR=simple');

        $bootstrap = new OtelBootstrap('site', 'matter-survey', '1.0.0', 'prod', disabled: false);
        $bootstrap->boot();

        $this->assertStringContainsString('service.namespace=matter-survey', (string) getenv('OTEL_RESOURCE_ATTRIBUTES'));
    }
}
