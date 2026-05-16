<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityHeadersSubscriberTest extends WebTestCase
{
    public function testSecurityHeadersAreSetOnHealthResponse(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/health');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        $this->assertResponseHeaderSame('X-Frame-Options', 'SAMEORIGIN');
        $this->assertResponseHeaderSame('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertResponseHeaderSame(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), interest-cohort=()',
        );
    }

    public function testHstsOnlySetWhenRequestIsSecure(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/health');

        $this->assertFalse(
            $client->getResponse()->headers->has('Strict-Transport-Security'),
            'HSTS must not be set on plain HTTP requests',
        );

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/health', server: ['HTTPS' => 'on']);

        $this->assertResponseHeaderSame(
            'Strict-Transport-Security',
            'max-age=31536000; includeSubDomains',
        );
    }
}
