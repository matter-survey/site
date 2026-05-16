<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\OtelDoctorCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class OtelDoctorCommandTest extends TestCase
{
    private const ENV_VARS = [
        'OTEL_SDK_DISABLED',
        'OTEL_TRACES_EXPORTER',
        'OTEL_METRICS_EXPORTER',
        'OTEL_LOGS_EXPORTER',
        'OTEL_EXPORTER_OTLP_ENDPOINT',
        'OTEL_EXPORTER_OTLP_PROTOCOL',
        'OTEL_TRACES_SAMPLER',
        'OTEL_TRACES_SAMPLER_ARG',
        'OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED',
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
    }

    public function testDisabledSdkExitsZero(): void
    {
        putenv('OTEL_SDK_DISABLED=true');

        $tester = $this->makeTester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('SDK is disabled', $tester->getDisplay());
    }

    public function testEnabledWithoutEndpointFails(): void
    {
        putenv('OTEL_SDK_DISABLED=false');
        putenv('OTEL_TRACES_EXPORTER=otlp');

        $tester = $this->makeTester();
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('OTEL_EXPORTER_OTLP_ENDPOINT is required', $tester->getDisplay());
    }

    public function testEnabledWithEndpointSucceeds(): void
    {
        putenv('OTEL_SDK_DISABLED=false');
        putenv('OTEL_TRACES_EXPORTER=otlp');
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=https://otlp.example/');
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=http/json');
        putenv('OTEL_TRACES_SAMPLER=parentbased_traceidratio');
        putenv('OTEL_TRACES_SAMPLER_ARG=1.0');

        $tester = $this->makeTester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Configuration looks healthy', $tester->getDisplay());
    }

    public function testRegistryLookupsFlagAppearsInEnvironmentTable(): void
    {
        putenv('OTEL_SDK_DISABLED=true');
        putenv('OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED=true');

        $tester = $this->makeTester();
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED', $display);
        $this->assertMatchesRegularExpression(
            '/OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED\s+true/',
            $display,
        );
    }

    public function testRegistryLookupsFlagUnsetRendersAsUnset(): void
    {
        putenv('OTEL_SDK_DISABLED=true');

        $tester = $this->makeTester();
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertMatchesRegularExpression(
            '/OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED\s+<unset>/',
            $display,
        );
    }

    public function testInvalidSamplerArgFails(): void
    {
        putenv('OTEL_SDK_DISABLED=false');
        putenv('OTEL_TRACES_EXPORTER=none');
        putenv('OTEL_TRACES_SAMPLER=parentbased_traceidratio');
        putenv('OTEL_TRACES_SAMPLER_ARG=banana');

        $tester = $this->makeTester();
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('OTEL_TRACES_SAMPLER_ARG', $tester->getDisplay());
    }

    private function makeTester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new OtelDoctorCommand());

        return new CommandTester($application->find('app:otel:doctor'));
    }
}
