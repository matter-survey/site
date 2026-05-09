<?php

declare(strict_types=1);

namespace App\Observability\Subscriber;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use OpenTelemetry\Context\ScopeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestTracingSubscriber implements EventSubscriberInterface
{
    private const SPAN_ATTR = '_otel_span';
    private const SCOPE_ATTR = '_otel_scope';
    private const TRACER_NAME = 'app.matter-survey';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 100]],
            KernelEvents::CONTROLLER => [['onKernelController', 100]],
            KernelEvents::EXCEPTION => [['onKernelException', 100]],
            KernelEvents::TERMINATE => [['onKernelTerminate', -100]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $parentContext = Globals::propagator()->extract(
            $request->headers->all(),
            ArrayAccessGetterSetter::getInstance(),
        );

        $span = Globals::tracerProvider()
            ->getTracer(self::TRACER_NAME)
            ->spanBuilder(strtoupper($request->getMethod()))
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($parentContext)
            ->setAttribute('http.request.method', strtoupper($request->getMethod()))
            ->setAttribute('url.path', $request->getPathInfo())
            ->setAttribute('url.scheme', $request->getScheme())
            ->setAttribute('server.address', $request->getHost())
            ->startSpan();

        $request->attributes->set(self::SPAN_ATTR, $span);
        $request->attributes->set(self::SCOPE_ATTR, $span->activate());
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $span = $request->attributes->get(self::SPAN_ATTR);

        if (!$span instanceof SpanInterface) {
            return;
        }

        $route = $request->attributes->get('_route');
        if (\is_string($route) && '' !== $route) {
            $span->updateName(strtoupper($request->getMethod()).' '.$route);
            $span->setAttribute('http.route', $route);
        }
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $span = $event->getRequest()->attributes->get(self::SPAN_ATTR);
        if (!$span instanceof SpanInterface) {
            return;
        }

        $span->recordException($event->getThrowable());
        $span->setStatus(StatusCode::STATUS_ERROR, $event->getThrowable()->getMessage());
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $span = $request->attributes->get(self::SPAN_ATTR);
        $scope = $request->attributes->get(self::SCOPE_ATTR);

        if ($span instanceof SpanInterface) {
            $span->setAttribute('http.response.status_code', $event->getResponse()->getStatusCode());
            $span->end();
            $request->attributes->remove(self::SPAN_ATTR);
        }
        if ($scope instanceof ScopeInterface) {
            $scope->detach();
            $request->attributes->remove(self::SCOPE_ATTR);
        }

        if (\function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }
}
