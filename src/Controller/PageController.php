<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PageController extends AbstractController
{
    #[Route('/about', name: 'page_about', methods: ['GET'])]
    public function about(): Response
    {
        return $this->render('page/about.html.twig');
    }

    #[Route('/faq', name: 'page_faq', methods: ['GET'])]
    public function faq(): Response
    {
        return $this->render('page/faq.html.twig');
    }

    #[Route('/glossary', name: 'page_glossary', methods: ['GET'])]
    public function glossary(): Response
    {
        return $this->render('page/glossary.html.twig');
    }
}
