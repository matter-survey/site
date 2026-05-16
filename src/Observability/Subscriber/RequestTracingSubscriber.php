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
    private const TRACER_NAME = 'app.matter-survey';

    /**
     * Per-request OTel state, keyed by Request object. Stored off-Request because
     * anything placed in `$request->attributes` is walked by Symfony Security's
     * HttpUtils::generateUri() when building redirects — and OTel Span/Scope
     * objects carry circular references that explode `UrlGenerator`'s recursive
     * `get_object_vars` walk on PHP 8.4's tighter `zend.max_allowed_stack_size`.
     *
     * @var \SplObjectStorage<\Symfony\Component\HttpFoundation\Request, array{span: SpanInterface, scope: ScopeInterface}>
     */
    private \SplObjectStorage $state;

    public function __construct()
    {
        $this->state = new \SplObjectStorage();
    }

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

        $this->state[$request] = ['span' => $span, 'scope' => $span->activate()];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!isset($this->state[$request])) {
            return;
        }
        $span = $this->state[$request]['span'];

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

        $request = $event->getRequest();
        if (!isset($this->state[$request])) {
            return;
        }
        $span = $this->state[$request]['span'];

        $span->recordException($event->getThrowable());
        $span->setStatus(StatusCode::STATUS_ERROR, $event->getThrowable()->getMessage());
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();

        if (isset($this->state[$request])) {
            $entry = $this->state[$request];
            $entry['span']->setAttribute('http.response.status_code', $event->getResponse()->getStatusCode());
            $entry['span']->end();
            $entry['scope']->detach();
            unset($this->state[$request]);
        }

        if (\function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }
}
