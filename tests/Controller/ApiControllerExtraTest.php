<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * Functional tests for ApiController branches not covered by the larger
 * ApiControllerTest suite: the docs redirect and the early-exit error
 * paths (empty body, invalid JSON).
 */
final class ApiControllerExtraTest extends KernelTestCase
{
    use HasBrowser;

    public function testApiDocsRedirect(): void
    {
        $this->browser()
            ->interceptRedirects()
            ->visit('/api/')
            ->assertRedirected();
    }

    public function testApiSubmitRejectsEmptyBody(): void
    {
        $this->browser()
            ->post('/api/submit', [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => '',
            ])
            ->assertStatus(400)
            ->assertJson()
            ->assertContains('"status":"error"')
            ->assertContains('Empty request body');
    }

    public function testApiSubmitRejectsInvalidJson(): void
    {
        $this->browser()
            ->post('/api/submit', [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => '{not-valid-json',
            ])
            ->assertStatus(400)
            ->assertJson()
            ->assertContains('Invalid JSON');
    }
}
