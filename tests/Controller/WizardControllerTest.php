<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WizardControllerTest extends WebTestCase
{
    public function testWizardIndexLoadsStep1(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.wizard-container');
        $this->assertSelectorExists('.category-grid');
    }

    public function testWizardStep1ShowsCategories(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=1');

        $this->assertResponseIsSuccessful();
        // Should show category cards
        $this->assertSelectorExists('.category-card');
    }

    public function testCannotSkipToStep2WithoutCategory(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=2');

        $this->assertResponseRedirects('/wizard?step=1');
    }

    public function testCannotSkipToStep3WithoutCategory(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=3');

        $this->assertResponseRedirects('/wizard?step=1');
    }

    public function testStep2LoadsWithCategory(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=2&category=Lights');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.feature-section');
    }

    public function testStep3LoadsWithCategory(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=3&category=Lights');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.device-search-container');
    }

    public function testResultsRedirectsToDeviceIndex(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard/results?category=Lights');

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertRouteSame('device_index');
    }

    public function testResultsIncludesDeviceTypeFilters(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard/results?category=Lights');

        $this->assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        // Should include device_types filter parameter
        $this->assertStringContainsString('device_types', (string) $location);
    }

    public function testResultsIncludesConnectivityFilter(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard/results?category=Lights&connectivity[]=thread');

        $this->assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('connectivity', (string) $location);
    }

    public function testDeviceSearchEndpoint(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard/device-search?q=test');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('results', $response);
        $this->assertIsArray($response['results']);
    }

    public function testDeviceSearchReturnsEmptyForShortQuery(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard/device-search?q=a');

        $this->assertResponseIsSuccessful();

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('results', $response);
        $this->assertEmpty($response['results']);
    }

    public function testWizardSetsSessionCookie(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard');

        $this->assertResponseIsSuccessful();
        $cookie = $client->getCookieJar()->get('wizard_session');
        $this->assertInstanceOf(\Symfony\Component\BrowserKit\Cookie::class, $cookie);
    }

    public function testProgressIndicatorShowsCorrectStep(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=2&category=Lights');

        $this->assertResponseIsSuccessful();
        // First step should be completed
        $this->assertSelectorExists('.wizard-step.completed');
        // Second step should be active
        $this->assertSelectorExists('.wizard-step.active');
    }

    public function testStep2FormSubmissionWithConnectivity(): void
    {
        $client = self::createClient();

        // Load step 2
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=2&category=Climate');
        $this->assertResponseIsSuccessful();

        // Submit the form by navigating directly (simulating form submission)
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=3&category=Climate&connectivity[]=thread&min_rating=&binding=');

        // Should navigate to step 3 successfully
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.device-search-container');
    }

    public function testStep3LoadsWithConnectivityParameter(): void
    {
        $client = self::createClient();

        // Navigate to step 3 with connectivity parameter - should not error
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=3&category=Climate&connectivity[]=thread');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.device-search-container');
    }

    public function testStep2WithEmptyMinRating(): void
    {
        $client = self::createClient();

        // Empty min_rating parameter should not cause Bad Request
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=2&category=Climate&min_rating=');

        $this->assertResponseIsSuccessful();
    }

    public function testStep3WithAllEmptyOptionalParams(): void
    {
        $client = self::createClient();

        // All optional params empty should not cause Bad Request
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=3&category=Climate&connectivity[]=&min_rating=&binding=');

        $this->assertResponseIsSuccessful();
    }

    // ========================================================================
    // Capability Filter Tests
    // ========================================================================

    public function testStep2ShowsCapabilitiesSection(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=2&category=Lights');

        $this->assertResponseIsSuccessful();
        // Should show capabilities section
        $this->assertSelectorTextContains('body', 'Device Capabilities');
        // Should have capability checkboxes
        $this->assertSelectorExists('input[name="capabilities[]"]');
    }

    public function testStep2PreservesCapabilitiesInForm(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=2&category=Lights&capabilities[]=dimming');

        $this->assertResponseIsSuccessful();
        // The dimming checkbox should be checked (checked attribute present means it's checked)
        $checkbox = $crawler->filter('input[name="capabilities[]"][value="dimming"][checked]');
        $this->assertGreaterThan(0, $checkbox->count(), 'Dimming checkbox should be checked');
    }

    public function testStep3LoadsWithCapabilitiesParameter(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=3&category=Lights&capabilities[]=dimming&capabilities[]=full_color');

        $this->assertResponseIsSuccessful();
    }

    public function testResultsIncludesCapabilitiesFilter(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard/results?category=Lights&capabilities[]=dimming');

        $this->assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('capabilities', (string) $location);
    }

    public function testCapabilitiesFilterIgnoresInvalidKeys(): void
    {
        $client = self::createClient();
        // Invalid capability key should be filtered out
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=2&category=Lights&capabilities[]=invalid_key');

        $this->assertResponseIsSuccessful();
    }
}
