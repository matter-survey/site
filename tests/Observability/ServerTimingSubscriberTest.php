<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\Subscriber\ServerTimingSubscriber;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ServerTimingSubscriberTest extends TestCase
{
    use InMemoryOtelTrait;

    protected function setUp(): void
    {
        $this->setUpOtel();
    }

    protected function tearDown(): void
    {
        $this->tearDownOtel();
    }

    public function testHeaderCarriesActiveSpanTraceContext(): void
    {
        $span = Globals::tracerProvider()
            ->getTracer('test')
            ->spanBuilder('GET test')
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();
        $scope = $span->activate();
        $context = $span->getContext();

        $response = new Response();
        $this->dispatch($response);

        $scope->detach();
        $span->end();

        $header = $response->headers->get('Server-Timing');
        $this->assertNotNull($header);
        $this->assertStringContainsString('traceparent;desc="00-', $header);
        $this->assertStringContainsString($context->getTraceId(), $header);
        $this->assertStringContainsString($context->getSpanId(), $header);
    }

    public function testNoHeaderWithoutActiveSpan(): void
    {
        $response = new Response();
        $this->dispatch($response);

        $this->assertFalse($response->headers->has('Server-Timing'));
    }

    private function dispatch(Response $response): void
    {
        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        new ServerTimingSubscriber()->onKernelResponse($event);
    }
}
