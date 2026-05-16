<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

class HealthControllerTest extends KernelTestCase
{
    use HasBrowser;

    public function testHealthEndpointReturnsOk(): void
    {
        $this->browser()
            ->visit('/health')
            ->assertSuccessful()
            ->assertHeaderContains('Content-Type', 'application/json')
            ->assertContains('"status":"healthy"')
            ->assertContains('"database":"ok"')
            ->assertContains('"timestamp":');
    }
}
