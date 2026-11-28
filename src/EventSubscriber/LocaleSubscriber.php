<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class LocaleSubscriber
{
    public function __construct(
        private readonly string $defaultLocale = 'en',
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // Get locale from route parameters, default to 'en'
        $locale = $request->attributes->get('_locale', $this->defaultLocale);
        $request->setLocale($locale);
    }
}
