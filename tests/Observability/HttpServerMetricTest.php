<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\Subscriber\RequestTracingSubscriber;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Drives the request lifecycle through {@see RequestTracingSubscriber} directly
 * (no kernel boot, so the disabled-by-default OtelBootstrap never replaces our
 * in-memory SDK) and asserts the semantic-convention HTTP server metric.
 */
final class HttpServerMetricTest extends TestCase
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

    public function testTerminateEmitsHttpServerRequestDuration(): void
    {
        $subscriber = new RequestTracingSubscriber();
        $kernel = $this->createStub(HttpKernelInterface::class);

        $request = Request::create('/device/foo', Request::METHOD_GET);
        $request->attributes->set('_route', 'device_show');

        $subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onKernelController(new ControllerEvent($kernel, static fn (): Response => new Response(), $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onKernelTerminate(new TerminateEvent($kernel, $request, new Response('', Response::HTTP_OK)));

        $metric = $this->recordedMetrics()['http.server.request.duration'] ?? null;
        $this->assertInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class, $metric, 'http.server.request.duration metric expected');
        $this->assertSame('s', $metric->unit);
        $this->assertInstanceOf(Histogram::class, $metric->data);

        $point = null;
        foreach ($metric->data->dataPoints as $point) {
            break;
        }
        $this->assertInstanceOf(\OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint::class, $point, 'expected at least one http.server.request.duration data point');
        $attrs = $point->attributes->toArray();
        $this->assertSame('GET', $attrs['http.request.method'] ?? null);
        $this->assertSame('http', $attrs['url.scheme'] ?? null);
        $this->assertSame(200, $attrs['http.response.status_code'] ?? null);
        $this->assertSame('device_show', $attrs['http.route'] ?? null);
        $this->assertSame(1, $point->count);
    }
}
