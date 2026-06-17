<?php

declare(strict_types=1);

namespace App\Observability\Subscriber;

use OpenTelemetry\API\Trace\Span;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Exposes the active server span's trace context on the response via a
 * `Server-Timing` header, so a browser navigation (which cannot send an
 * outgoing `traceparent`) can correlate the initial document load with its
 * backend trace. Only emitted when a valid, sampled span exists for the
 * request; the active span is still current at RESPONSE (it ends at TERMINATE).
 */
final class ServerTimingSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $context = Span::getCurrent()->getContext();
        if (!$context->isValid() || !$context->isSampled()) {
            return;
        }

        $value = sprintf(
            'traceparent;desc="00-%s-%s-%02x"',
            $context->getTraceId(),
            $context->getSpanId(),
            $context->getTraceFlags(),
        );

        $event->getResponse()->headers->set('Server-Timing', $value, false);
    }
}
