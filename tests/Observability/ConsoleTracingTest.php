<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\Subscriber\ConsoleTracingSubscriber;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\EventListener\ErrorListener;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ConsoleTracingTest extends TestCase
{
    /** @var \ArrayObject<int, ImmutableSpan> */
    private \ArrayObject $storage;
    private TracerProvider $tracerProvider;

    protected function setUp(): void
    {
        $this->storage = new \ArrayObject();
        $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter($this->storage)));

        Globals::reset();
        Sdk::builder()
            ->setTracerProvider($this->tracerProvider)
            ->buildAndRegisterGlobal();
    }

    protected function tearDown(): void
    {
        $this->tracerProvider->shutdown();
        Globals::reset();
    }

    public function testSuccessfulCommandProducesSpan(): void
    {
        $tester = $this->makeTester(new SuccessCommand());
        $tester->run(['command' => 'test:success']);

        $this->tracerProvider->forceFlush();
        $spans = iterator_to_array($this->storage->getIterator());

        $this->assertCount(1, $spans);
        $this->assertSame('command test:success', $spans[0]->getName());
        $this->assertSame(SpanKind::KIND_INTERNAL, $spans[0]->getKind());

        $attrs = $spans[0]->getAttributes()->toArray();
        $this->assertSame('test:success', $attrs['command.name']);
        $this->assertSame(0, $attrs['command.exit_code']);
    }

    public function testFailingCommandMarksSpanError(): void
    {
        $tester = $this->makeTester(new FailingCommand());
        $tester->run(['command' => 'test:failing']);

        $this->tracerProvider->forceFlush();
        $spans = iterator_to_array($this->storage->getIterator());

        $this->assertCount(1, $spans);
        $this->assertSame(2, $spans[0]->getAttributes()->toArray()['command.exit_code'] ?? null);
        $this->assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
    }

    private function makeTester(Command $command): ApplicationTester
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ConsoleTracingSubscriber());
        $dispatcher->addSubscriber(new ErrorListener());

        $app = new Application();
        $app->setAutoExit(false);
        $app->setDispatcher($dispatcher);
        $app->addCommand($command);

        return new ApplicationTester($app);
    }
}

#[AsCommand(name: 'test:success')]
final class SuccessCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}

#[AsCommand(name: 'test:failing')]
final class FailingCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return 2;
    }
}
