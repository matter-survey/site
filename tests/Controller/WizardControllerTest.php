<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class WizardControllerTest extends WebTestCase
{
    public function testWizardIndexLoadsStep1(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/wizard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.wizard-container');
        $this->assertSelectorExists('.category-grid');
    }

    public function testWizardStep1ShowsCategories(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/wizard?step=1');

        $this->assertResponseIsSuccessful();
        // Should show category cards
        $this->assertSelectorExists('.category-card');
    }

    public function testCannotSkipToStep2WithoutCategory(): void
    {
        $client = static::createClient();
        $client->request('GET', '/wizard?step=2');

        $this->assertResponseRedirects('/wizard?step=1');
    }

    public function testCannotSkipToStep3WithoutCategory(): void
    {
        $client = static::createClient();
        $client->request('GET', '/wizard?step=3');

        $this->assertResponseRedirects('/wizard?step=1');
    }

    public function testStep2LoadsWithCategory(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/wizard?step=2&category=Lights');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.feature-section');
    }

    public function testStep3LoadsWithCategory(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/wizard?step=3&category=Lights');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.device-search-container');
    }

    public function testResultsRedirectsToDeviceIndex(): void
    {
        $client = static::createClient();
        $client->request('GET', '/wizard/results?category=Lights');

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertRouteSame('device_index');
    }

    public function testResultsIncludesDeviceTypeFilters(): void
    {
        $client = static::createClient();
        $client->request('GET', '/wizard/results?category=Lights');

        $this->assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        // Should include device_types filter parameter
        $this->assertStringContainsString('device_types', $location);
    }

    public function testResultsIncludesConnectivityFilter(): void
    {
        $client = static::createClient();
        $client->request('GET', '/wizard/results?category=Lights&connectivity[]=thread');

        $this->assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('connectivity', $location);
    }

    public function testDeviceSearchEndpoint(): void
    {
        $client = static::createClient();
        $client->request('GET', '/wizard/device-search?q=test');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('results', $response);
        $this->assertIsArray($response['results']);
    }

    public function testDeviceSearchReturnsEmptyForShortQuery(): void
    {
        $client = static::createClient();
        $client->request('GET', '/wizard/device-search?q=a');

        $this->assertResponseIsSuccessful();

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('results', $response);
        $this->assertEmpty($response['results']);
    }

    public function testWizardSetsSessionCookie(): void
    {
        $client = static::createClient();
        $client->request('GET', '/wizard');

        $this->assertResponseIsSuccessful();
        $cookie = $client->getCookieJar()->get('wizard_session');
        $this->assertNotNull($cookie);
    }

    public function testProgressIndicatorShowsCorrectStep(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/wizard?step=2&category=Lights');

        $this->assertResponseIsSuccessful();
        // First step should be completed
        $this->assertSelectorExists('.wizard-step.completed');
        // Second step should be active
        $this->assertSelectorExists('.wizard-step.active');
    }

    public function testStep2FormSubmissionWithConnectivity(): void
    {
        $client = static::createClient();

        // Load step 2
        $crawler = $client->request('GET', '/wizard?step=2&category=Climate');
        $this->assertResponseIsSuccessful();

        // Submit the form by navigating directly (simulating form submission)
        $client->request('GET', '/wizard?step=3&category=Climate&connectivity[]=thread&min_rating=&binding=');

        // Should navigate to step 3 successfully
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.device-search-container');
    }

    public function testStep3LoadsWithConnectivityParameter(): void
    {
        $client = static::createClient();

        // Navigate to step 3 with connectivity parameter - should not error
        $crawler = $client->request('GET', '/wizard?step=3&category=Climate&connectivity[]=thread');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.device-search-container');
    }

    public function testStep2WithEmptyMinRating(): void
    {
        $client = static::createClient();

        // Empty min_rating parameter should not cause Bad Request
        $client->request('GET', '/wizard?step=2&category=Climate&min_rating=');

        $this->assertResponseIsSuccessful();
    }

    public function testStep3WithAllEmptyOptionalParams(): void
    {
        $client = static::createClient();

        // All optional params empty should not cause Bad Request
        $client->request('GET', '/wizard?step=3&category=Climate&connectivity[]=&min_rating=&binding=');

        $this->assertResponseIsSuccessful();
    }

    // ========================================================================
    // Capability Filter Tests
    // ========================================================================

    public function testStep2ShowsCapabilitiesSection(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/wizard?step=2&category=Lights');

        $this->assertResponseIsSuccessful();
        // Should show capabilities section
        $this->assertSelectorTextContains('body', 'Device Capabilities');
        // Should have capability checkboxes
        $this->assertSelectorExists('input[name="capabilities[]"]');
    }

    public function testStep2PreservesCapabilitiesInForm(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/wizard?step=2&category=Lights&capabilities[]=dimming');

        $this->assertResponseIsSuccessful();
        // The dimming checkbox should be checked (checked attribute present means it's checked)
        $checkbox = $crawler->filter('input[name="capabilities[]"][value="dimming"][checked]');
        $this->assertGreaterThan(0, $checkbox->count(), 'Dimming checkbox should be checked');
    }

    public function testStep3LoadsWithCapabilitiesParameter(): void
    {
        $client = static::createClient();
        $client->request('GET', '/wizard?step=3&category=Lights&capabilities[]=dimming&capabilities[]=full_color');

        $this->assertResponseIsSuccessful();
    }

    public function testResultsIncludesCapabilitiesFilter(): void
    {
        $client = static::createClient();
        $client->request('GET', '/wizard/results?category=Lights&capabilities[]=dimming');

        $this->assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('capabilities', $location);
    }

    public function testCapabilitiesFilterIgnoresInvalidKeys(): void
    {
        $client = static::createClient();
        // Invalid capability key should be filtered out
        $client->request('GET', '/wizard?step=2&category=Lights&capabilities[]=invalid_key');

        $this->assertResponseIsSuccessful();
    }
}
