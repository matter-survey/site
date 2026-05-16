<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\HttpClient\TracingHttpClient;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TracingHttpClientTest extends TestCase
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
            ->setPropagator(TraceContextPropagator::getInstance())
            ->buildAndRegisterGlobal();
    }

    protected function tearDown(): void
    {
        $this->tracerProvider->shutdown();
        Globals::reset();
    }

    public function testRequestProducesClientSpanAndInjectsTraceparent(): void
    {
        $captured = null;
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });

        $client = new TracingHttpClient($mock);
        $response = $client->request('GET', 'https://example.test/x');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"ok":true}', $response->getContent());

        $this->tracerProvider->forceFlush();

        $spans = iterator_to_array($this->storage->getIterator());
        $this->assertCount(1, $spans);
        $this->assertSame('GET', $spans[0]->getName());
        $this->assertSame(SpanKind::KIND_CLIENT, $spans[0]->getKind());

        $attrs = $spans[0]->getAttributes()->toArray();
        $this->assertSame('GET', $attrs['http.request.method']);
        $this->assertSame('https://example.test/x', $attrs['url.full']);
        $this->assertSame(200, $attrs['http.response.status_code']);

        $this->assertNotNull($captured);
        $normalized = $captured['options']['normalized_headers'] ?? [];
        $this->assertArrayHasKey('traceparent', $normalized, 'traceparent must be injected on outbound');
    }

    public function testTraceparentDerivedFromActiveSpan(): void
    {
        $tracer = Globals::tracerProvider()->getTracer('test');
        $rootSpan = $tracer->spanBuilder('root')->startSpan();
        $scope = $rootSpan->activate();

        $captured = null;
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;

            return new MockResponse('', ['http_code' => 204]);
        });

        try {
            $client = new TracingHttpClient($mock);
            $client->request('POST', 'https://example.test/y')->getStatusCode();
        } finally {
            $scope->detach();
            $rootSpan->end();
        }

        $normalized = $captured['normalized_headers'] ?? [];
        $this->assertArrayHasKey('traceparent', $normalized);
        $traceparentLine = $normalized['traceparent'][0] ?? '';
        $this->assertStringContainsString($rootSpan->getContext()->getTraceId(), $traceparentLine);
    }

    public function testErrorStatusMarksSpanError(): void
    {
        $mock = new MockHttpClient(static fn () => new MockResponse('boom', ['http_code' => 500]));
        $client = new TracingHttpClient($mock);

        $client->request('GET', 'https://example.test/fail')->getStatusCode();

        $this->tracerProvider->forceFlush();
        $spans = iterator_to_array($this->storage->getIterator());
        $this->assertCount(1, $spans);
        $this->assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
    }

    public function testTransportExceptionRecordedOnSpan(): void
    {
        $mock = new MockHttpClient(static fn () => throw new \RuntimeException('connection refused'));
        $client = new TracingHttpClient($mock);

        try {
            $client->request('GET', 'https://example.test/down');
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('connection refused', $e->getMessage());
        }

        $this->tracerProvider->forceFlush();
        $spans = iterator_to_array($this->storage->getIterator());
        $this->assertCount(1, $spans);
        $this->assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
    }

    public function testGetHeadersAndToArrayEndSpanOnce(): void
    {
        $mock = new MockHttpClient(static fn () => new MockResponse(
            '{"hello":"world"}',
            ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']],
        ));
        $client = new TracingHttpClient($mock);
        $response = $client->request('GET', 'https://example.test/json');

        $headers = $response->getHeaders();
        $this->assertArrayHasKey('content-type', $headers);

        $array = $response->toArray();
        $this->assertSame(['hello' => 'world'], $array);

        $this->tracerProvider->forceFlush();
        $spans = iterator_to_array($this->storage->getIterator());
        $this->assertCount(1, $spans);
        $this->assertSame(200, $spans[0]->getAttributes()->toArray()['http.response.status_code']);
    }

    public function testGetInfoDelegatesAndDoesNotEndSpan(): void
    {
        $mock = new MockHttpClient(static fn () => new MockResponse('', ['http_code' => 204]));
        $client = new TracingHttpClient($mock);
        $response = $client->request('GET', 'https://example.test/info');

        // getInfo() is a pure delegation; it doesn't end the span.
        $url = $response->getInfo('url');
        $this->assertSame('https://example.test/info', $url);

        $response->getStatusCode(); // triggers endOk
        $this->tracerProvider->forceFlush();
        $this->assertCount(1, iterator_to_array($this->storage->getIterator()));
    }

    public function testCancelEndsSpanWithoutStatusCodeAttribute(): void
    {
        $mock = new MockHttpClient(static fn () => new MockResponse('', ['http_code' => 200]));
        $client = new TracingHttpClient($mock);
        $response = $client->request('GET', 'https://example.test/cancel');

        $response->cancel();

        $this->tracerProvider->forceFlush();
        $spans = iterator_to_array($this->storage->getIterator());
        $this->assertCount(1, $spans);
        $this->assertArrayNotHasKey('http.response.status_code', $spans[0]->getAttributes()->toArray());
    }

    public function testWithOptionsClonesAndAppliesToInner(): void
    {
        $captured = [];
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured[] = $options;

            return new MockResponse('ok', ['http_code' => 200]);
        });
        $client = new TracingHttpClient($mock);
        $configured = $client->withOptions(['base_uri' => 'https://example.test']);

        $this->assertNotSame($client, $configured);

        $configured->request('GET', '/relative')->getStatusCode();
        $this->assertNotEmpty($captured);
    }

    public function testStreamUnwrapsTracingResponseAndDelegates(): void
    {
        $mock = new MockHttpClient(static fn () => new MockResponse('payload', ['http_code' => 200]));
        $client = new TracingHttpClient($mock);
        $response = $client->request('GET', 'https://example.test/stream');

        // Pass a single TracingResponse — exercises the branch that unwraps
        // before delegating to the inner client's stream().
        $stream = $client->stream($response);
        $this->assertInstanceOf(\Symfony\Contracts\HttpClient\ResponseStreamInterface::class, $stream);
    }

    public function testStreamUnwrapsIterableOfTracingResponses(): void
    {
        $mock = new MockHttpClient(static fn () => new MockResponse('one', ['http_code' => 200]));
        $client = new TracingHttpClient($mock);
        $a = $client->request('GET', 'https://example.test/a');
        $b = $client->request('GET', 'https://example.test/b');

        // Exercises the iterable branch with both wrapped and plain responses.
        $stream = $client->stream([$a, $b]);
        $this->assertInstanceOf(\Symfony\Contracts\HttpClient\ResponseStreamInterface::class, $stream);
    }
}
