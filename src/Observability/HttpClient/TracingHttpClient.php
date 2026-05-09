<?php

declare(strict_types=1);

namespace App\Observability\HttpClient;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Decorates Symfony's HttpClient to:
 *   - inject W3C TraceContext headers on every outbound request,
 *   - emit one CLIENT span per request with HTTP semantic-convention attributes.
 */
final class TracingHttpClient implements HttpClientInterface
{
    public function __construct(private HttpClientInterface $inner)
    {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $tracer = Globals::tracerProvider()->getTracer('app.matter-survey');
        $span = $tracer
            ->spanBuilder($method)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('http.request.method', $method)
            ->setAttribute('url.full', $url)
            ->startSpan();

        $carrier = [];
        Globals::propagator()->inject($carrier, null, Context::getCurrent()->withContextValue($span));

        $headers = $options['headers'] ?? [];
        foreach ($carrier as $name => $value) {
            $headers[$name] = $value;
        }
        $options['headers'] = $headers;

        try {
            $response = $this->inner->request($method, $url, $options);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->end();

            throw $e;
        }

        return new TracingResponse($response, $span);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof TracingResponse) {
            $responses = $responses->unwrap();
        } elseif (is_iterable($responses)) {
            $responses = (function () use ($responses) {
                foreach ($responses as $r) {
                    yield $r instanceof TracingResponse ? $r->unwrap() : $r;
                }
            })();
        }

        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);

        return $clone;
    }
}

/**
 * @internal
 */
final class TracingResponse implements ResponseInterface
{
    private bool $spanEnded = false;

    public function __construct(
        private readonly ResponseInterface $inner,
        private readonly SpanInterface $span,
    ) {
    }

    public function unwrap(): ResponseInterface
    {
        return $this->inner;
    }

    public function getStatusCode(): int
    {
        try {
            $code = $this->inner->getStatusCode();
        } catch (\Throwable $e) {
            $this->endWithException($e);

            throw $e;
        }
        $this->endOk($code);

        return $code;
    }

    public function getHeaders(bool $throw = true): array
    {
        try {
            $headers = $this->inner->getHeaders($throw);
            $this->endOk($this->inner->getStatusCode());

            return $headers;
        } catch (\Throwable $e) {
            $this->endWithException($e);

            throw $e;
        }
    }

    public function getContent(bool $throw = true): string
    {
        try {
            $content = $this->inner->getContent($throw);
            $this->endOk($this->inner->getStatusCode());

            return $content;
        } catch (\Throwable $e) {
            $this->endWithException($e);

            throw $e;
        }
    }

    public function toArray(bool $throw = true): array
    {
        try {
            $array = $this->inner->toArray($throw);
            $this->endOk($this->inner->getStatusCode());

            return $array;
        } catch (\Throwable $e) {
            $this->endWithException($e);

            throw $e;
        }
    }

    public function cancel(): void
    {
        $this->inner->cancel();
        $this->endOk(0);
    }

    public function getInfo(?string $type = null): mixed
    {
        return $this->inner->getInfo($type);
    }

    public function __destruct()
    {
        $this->endOk(0);
    }

    private function endOk(int $statusCode): void
    {
        if ($this->spanEnded) {
            return;
        }
        $this->spanEnded = true;
        if ($statusCode > 0) {
            $this->span->setAttribute('http.response.status_code', $statusCode);
            if ($statusCode >= 400) {
                $this->span->setStatus(StatusCode::STATUS_ERROR);
            }
        }
        $this->span->end();
    }

    private function endWithException(\Throwable $e): void
    {
        if ($this->spanEnded) {
            return;
        }
        $this->spanEnded = true;
        $this->span->recordException($e);
        $this->span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
        $this->span->end();
    }
}
