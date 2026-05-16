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

    public function testThrowingCommandRecordsExceptionOnSpan(): void
    {
        $tester = $this->makeTester(new ThrowingCommand());
        $tester->run(['command' => 'test:throws'], ['capture_stderr_separately' => true]);

        $this->tracerProvider->forceFlush();
        $spans = iterator_to_array($this->storage->getIterator());

        $this->assertCount(1, $spans);
        /** @var ImmutableSpan $span */
        $span = $spans[0];

        // Both onError (records exception + sets ERROR with message) and
        // onTerminate (sets ERROR without description, because exit code != 0)
        // run; the second setStatus call clears the description. What we can
        // reliably assert is the final ERROR code and the recorded exception
        // event from onError.
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());

        $events = $span->getEvents();
        $this->assertNotEmpty($events, 'exception event should be recorded on the span');
        $this->assertSame('exception', $events[0]->getName());
        $this->assertSame('kaboom', $events[0]->getAttributes()->toArray()['exception.message'] ?? null);
    }

    public function testOnCommandWithNullCommandIsNoOp(): void
    {
        $subscriber = new ConsoleTracingSubscriber();
        $event = new \Symfony\Component\Console\Event\ConsoleCommandEvent(
            null,
            new \Symfony\Component\Console\Input\StringInput(''),
            new \Symfony\Component\Console\Output\NullOutput(),
        );

        $subscriber->onCommand($event);

        $this->tracerProvider->forceFlush();
        $this->assertCount(0, iterator_to_array($this->storage->getIterator()));
    }

    public function testOnErrorWithoutActiveSpanIsNoOp(): void
    {
        $subscriber = new ConsoleTracingSubscriber();
        $event = new \Symfony\Component\Console\Event\ConsoleErrorEvent(
            new \Symfony\Component\Console\Input\StringInput(''),
            new \Symfony\Component\Console\Output\NullOutput(),
            new \RuntimeException('orphan'),
        );

        // Calling onError before onCommand → no active span; must be a no-op.
        $subscriber->onError($event);

        $this->tracerProvider->forceFlush();
        $this->assertCount(0, iterator_to_array($this->storage->getIterator()));
    }

    public function testOnTerminateWithoutActiveSpanIsNoOp(): void
    {
        $subscriber = new ConsoleTracingSubscriber();
        $event = new \Symfony\Component\Console\Event\ConsoleTerminateEvent(
            new SuccessCommand(),
            new \Symfony\Component\Console\Input\StringInput(''),
            new \Symfony\Component\Console\Output\NullOutput(),
            0,
        );

        $subscriber->onTerminate($event);

        $this->tracerProvider->forceFlush();
        $this->assertCount(0, iterator_to_array($this->storage->getIterator()));
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

#[AsCommand(name: 'test:throws')]
final class ThrowingCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        throw new \RuntimeException('kaboom');
    }
}
