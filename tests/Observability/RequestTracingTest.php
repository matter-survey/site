<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RequestTracingTest extends WebTestCase
{
    /** @var \ArrayObject<int, ImmutableSpan> */
    private \ArrayObject $storage;
    private TracerProvider $tracerProvider;

    protected function setUp(): void
    {
        $this->storage = new \ArrayObject();
        $exporter = new InMemoryExporter($this->storage);
        $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor($exporter));

        Globals::reset();
        Sdk::builder()
            ->setTracerProvider($this->tracerProvider)
            ->setPropagator(TraceContextPropagator::getInstance())
            ->buildAndRegisterGlobal();
    }

    protected function tearDown(): void
    {
        $this->tracerProvider->shutdown();
        Globals::reset();
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testHealthEndpointEmitsRootServerSpan(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');
        $this->assertResponseIsSuccessful();

        $this->tracerProvider->forceFlush();

        /** @var list<ImmutableSpan> $spans */
        $spans = iterator_to_array($this->storage->getIterator());
        $serverSpans = array_values(array_filter($spans, static fn (ImmutableSpan $s) => SpanKind::KIND_SERVER === $s->getKind()));

        $this->assertCount(1, $serverSpans, 'Exactly one SERVER kind span expected');

        $span = $serverSpans[0];
        $attrs = $span->getAttributes()->toArray();

        $this->assertSame('GET health_check', $span->getName());
        $this->assertSame('GET', $attrs['http.request.method'] ?? null);
        $this->assertSame('/health', $attrs['url.path'] ?? null);
        $this->assertSame('health_check', $attrs['http.route'] ?? null);
        $this->assertSame(200, $attrs['http.response.status_code'] ?? null);
    }

    public function testRequestAttributesAreNotPollutedWithOtelObjects(): void
    {
        // Regression: storing Span/Scope objects in $request->attributes broke
        // Symfony Security redirects because HttpUtils::generateUri() passes
        // attributes wholesale to the UrlGenerator, which walks objects via
        // get_object_vars; the Span's circular internals then exploded the
        // recursion under PHP 8.4's default zend.max_allowed_stack_size.
        $client = static::createClient();
        $client->request('GET', '/health');
        $this->assertResponseIsSuccessful();

        $attrs = $client->getRequest()->attributes->all();
        foreach ($attrs as $key => $value) {
            $this->assertNotInstanceOf(
                \OpenTelemetry\API\Trace\SpanInterface::class,
                $value,
                sprintf('Request attribute "%s" must not be an OTel Span.', $key),
            );
            $this->assertNotInstanceOf(
                \OpenTelemetry\Context\ScopeInterface::class,
                $value,
                sprintf('Request attribute "%s" must not be an OTel Scope.', $key),
            );
        }
    }

    public function testIncomingTraceparentIsHonored(): void
    {
        $traceId = '0af7651916cd43dd8448eb211c80319c';
        $parentSpanId = 'b7ad6b7169203331';
        $traceparent = sprintf('00-%s-%s-01', $traceId, $parentSpanId);

        $client = static::createClient();
        $client->request('GET', '/health', [], [], ['HTTP_TRACEPARENT' => $traceparent]);
        $this->assertResponseIsSuccessful();

        $this->tracerProvider->forceFlush();

        /** @var list<ImmutableSpan> $spans */
        $spans = iterator_to_array($this->storage->getIterator());
        $serverSpans = array_values(array_filter($spans, static fn (ImmutableSpan $s) => SpanKind::KIND_SERVER === $s->getKind()));

        $this->assertCount(1, $serverSpans);
        $span = $serverSpans[0];

        $this->assertSame($traceId, $span->getTraceId());
        $this->assertSame($parentSpanId, $span->getParentSpanId());
    }
}
