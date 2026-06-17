<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * English is the default locale and is served without a path prefix (German
 * uses `/de`). External links and crawlers nonetheless guess that `/en` mirrors
 * `/de`, producing a stream of 404s. This controller catches `/en` and any
 * `/en/...` deep path and 301-redirects to the unprefixed canonical URL,
 * preserving the query string.
 */
class LocaleRedirectController extends AbstractController
{
    #[Route('/en{path}', name: 'locale_en_redirect', requirements: ['path' => '(/.*)?'], defaults: ['path' => ''], methods: ['GET', 'HEAD'], priority: -10)]
    public function redirectEnglish(string $path, Request $request): RedirectResponse
    {
        $target = '/'.ltrim($path, '/');

        $query = $request->getQueryString();
        if (null !== $query && '' !== $query) {
            $target .= '?'.$query;
        }

        return $this->redirect($target, Response::HTTP_MOVED_PERMANENTLY);
    }
}
