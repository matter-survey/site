<?php

declare(strict_types=1);

namespace App\Observability\Subscriber;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ConsoleTracingSubscriber implements EventSubscriberInterface
{
    private ?SpanInterface $span = null;
    private ?ScopeInterface $scope = null;

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => [['onCommand', 100]],
            ConsoleEvents::ERROR => [['onError', 100]],
            ConsoleEvents::TERMINATE => [['onTerminate', -100]],
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        if (!$command instanceof \Symfony\Component\Console\Command\Command) {
            return;
        }

        $name = $command->getName() ?? 'unknown';

        $this->span = Globals::tracerProvider()
            ->getTracer('app.matter-survey')
            ->spanBuilder('command '.$name)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('command.name', $name)
            ->setAttribute('command.argv', (string) $event->getInput())
            ->startSpan();

        $this->scope = $this->span->activate();

        // Some Symfony commands (cache:clear) reboot the kernel mid-execution,
        // which replaces this subscriber instance — meaning onTerminate never
        // fires on the same instance. Hook PHP shutdown so the scope and span
        // are still closed cleanly when that happens.
        register_shutdown_function(function (): void {
            $this->scope?->detach();
            $this->scope = null;
            $this->span?->end();
            $this->span = null;
        });
    }

    public function onError(ConsoleErrorEvent $event): void
    {
        if (!$this->span instanceof SpanInterface) {
            return;
        }
        $this->span->recordException($event->getError());
        $this->span->setStatus(StatusCode::STATUS_ERROR, $event->getError()->getMessage());
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        if (!$this->span instanceof SpanInterface) {
            return;
        }

        $this->span->setAttribute('command.exit_code', $event->getExitCode());
        if (0 !== $event->getExitCode()) {
            $this->span->setStatus(StatusCode::STATUS_ERROR);
        }

        $this->span->end();
        $this->scope?->detach();

        $this->span = null;
        $this->scope = null;
    }
}
