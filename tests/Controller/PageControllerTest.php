<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

class PageControllerTest extends KernelTestCase
{
    use HasBrowser;

    public function testAboutPageRenders(): void
    {
        $this->browser()
            ->visit('/about')
            ->assertSuccessful()
            ->assertHtml();
    }

    public function testFaqPageRenders(): void
    {
        $this->browser()
            ->visit('/faq')
            ->assertSuccessful()
            ->assertHtml();
    }

    public function testGlossaryPageRenders(): void
    {
        $this->browser()
            ->visit('/glossary')
            ->assertSuccessful()
            ->assertHtml();
    }
}
