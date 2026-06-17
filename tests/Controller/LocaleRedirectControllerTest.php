<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class LocaleRedirectControllerTest extends WebTestCase
{
    public function testBareEnPrefixRedirectsToRoot(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/en');

        $this->assertResponseStatusCodeSame(301);
        $this->assertResponseRedirects('/');
    }

    public function testDeepEnPathRedirectsToUnprefixedEquivalent(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/en/about');

        $this->assertResponseStatusCodeSame(301);
        $this->assertResponseRedirects('/about');
    }

    public function testQueryStringIsPreserved(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/en/vendors?page=2');

        $this->assertResponseStatusCodeSame(301);
        $this->assertResponseRedirects('/vendors?page=2');
    }

    public function testUnprefixedRouteIsNotAffected(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/about');

        $this->assertResponseIsSuccessful();
    }
}
