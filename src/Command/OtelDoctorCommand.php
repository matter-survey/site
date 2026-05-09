<?php

declare(strict_types=1);

namespace App\Command;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\NoopLoggerProvider;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:otel:doctor',
    description: 'Print resolved OpenTelemetry configuration and verify it makes sense.',
)]
final class OtelDoctorCommand extends Command
{
    private const RELEVANT_VARS = [
        'OTEL_SDK_DISABLED',
        'OTEL_SERVICE_NAME',
        'OTEL_SERVICE_VERSION',
        'OTEL_RESOURCE_ATTRIBUTES',
        'OTEL_EXPORTER_OTLP_ENDPOINT',
        'OTEL_EXPORTER_OTLP_PROTOCOL',
        'OTEL_EXPORTER_OTLP_HEADERS',
        'OTEL_TRACES_EXPORTER',
        'OTEL_METRICS_EXPORTER',
        'OTEL_LOGS_EXPORTER',
        'OTEL_PHP_TRACES_PROCESSOR',
        'OTEL_TRACES_SAMPLER',
        'OTEL_TRACES_SAMPLER_ARG',
        'OTEL_PROPAGATORS',
        'OTEL_LOG_LEVEL',
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('OpenTelemetry doctor');

        $disabled = $this->boolEnv('OTEL_SDK_DISABLED');

        $rows = [];
        foreach (self::RELEVANT_VARS as $name) {
            $value = $this->getEnv($name);
            if ('OTEL_EXPORTER_OTLP_HEADERS' === $name && null !== $value && '' !== $value) {
                $value = $this->maskHeaders($value);
            }
            $rows[] = [$name, $value ?? '<unset>'];
        }
        $io->section('Environment');
        $io->table(['Variable', 'Value'], $rows);

        $io->section('Runtime providers');
        $io->definitionList(
            ['SDK disabled' => $disabled ? 'yes' : 'no'],
            ['TracerProvider' => $this->describeProvider(Globals::tracerProvider(), NoopTracerProvider::class)],
            ['MeterProvider' => $this->describeProvider(Globals::meterProvider(), NoopMeterProvider::class)],
            ['LoggerProvider' => $this->describeProvider(Globals::loggerProvider(), NoopLoggerProvider::class)],
            ['Propagator' => Globals::propagator()::class],
            ['fastcgi_finish_request' => \function_exists('fastcgi_finish_request') ? 'available' : 'unavailable'],
            ['php_sapi_name' => \PHP_SAPI],
        );

        $issues = $this->validate($disabled);
        if ([] === $issues) {
            $io->success($disabled ? 'SDK is disabled (default-safe state).' : 'Configuration looks healthy.');

            return Command::SUCCESS;
        }

        $io->error('Found '.\count($issues).' configuration issue(s):');
        foreach ($issues as $issue) {
            $io->writeln(' - '.$issue);
        }

        return Command::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function validate(bool $disabled): array
    {
        if ($disabled) {
            return [];
        }

        $issues = [];

        $tracesExporter = $this->getEnv('OTEL_TRACES_EXPORTER') ?? 'otlp';
        $logsExporter = $this->getEnv('OTEL_LOGS_EXPORTER') ?? 'none';
        $metricsExporter = $this->getEnv('OTEL_METRICS_EXPORTER') ?? 'none';
        $needsEndpoint = 'otlp' === $tracesExporter || 'otlp' === $logsExporter || 'otlp' === $metricsExporter;

        if ($needsEndpoint && (null === $this->getEnv('OTEL_EXPORTER_OTLP_ENDPOINT') || '' === $this->getEnv('OTEL_EXPORTER_OTLP_ENDPOINT'))) {
            $issues[] = 'OTEL_EXPORTER_OTLP_ENDPOINT is required when any signal uses the otlp exporter.';
        }

        $protocol = $this->getEnv('OTEL_EXPORTER_OTLP_PROTOCOL');
        if (null !== $protocol && !\in_array($protocol, ['http/json', 'http/protobuf', 'grpc'], true)) {
            $issues[] = sprintf('OTEL_EXPORTER_OTLP_PROTOCOL=%s is not a recognised OTLP protocol.', $protocol);
        }

        $sampler = $this->getEnv('OTEL_TRACES_SAMPLER');
        if ('parentbased_traceidratio' === $sampler || 'traceidratio' === $sampler) {
            $arg = $this->getEnv('OTEL_TRACES_SAMPLER_ARG');
            if (null === $arg || !is_numeric($arg) || (float) $arg < 0.0 || (float) $arg > 1.0) {
                $issues[] = sprintf('OTEL_TRACES_SAMPLER=%s requires OTEL_TRACES_SAMPLER_ARG to be a number in [0.0, 1.0].', $sampler);
            }
        }

        return $issues;
    }

    private function describeProvider(object $provider, string $noopClass): string
    {
        $class = $provider::class;

        return is_a($provider, $noopClass) ? $class.' (no-op)' : $class;
    }

    private function getEnv(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        if (false === $value) {
            return null;
        }

        return (string) $value;
    }

    private function boolEnv(string $name): bool
    {
        $value = $this->getEnv($name);
        if (null === $value) {
            return false;
        }

        return \in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private function maskHeaders(string $headers): string
    {
        return preg_replace('/=([^,]{4})[^,]*/', '=$1…<masked>', $headers) ?? $headers;
    }
}
