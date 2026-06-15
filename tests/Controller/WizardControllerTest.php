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
        // Step 3 is now the "how it fits your home" step with the explainer.
        $this->assertSelectorExists('.wizard-explainer');
    }

    public function testStep4LoadsWithCategory(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=4&category=Lights');

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

        $response = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('results', $response);
        $this->assertIsArray($response['results']);
    }

    public function testDeviceSearchReturnsEmptyForShortQuery(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard/device-search?q=a');

        $this->assertResponseIsSuccessful();

        $response = json_decode((string) $client->getResponse()->getContent(), true);
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

    public function testStep3FormSubmissionWithConnectivity(): void
    {
        $client = self::createClient();

        // Connectivity is now collected on step 3 (home setup) and carried to step 4.
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=3&category=Climate');
        $this->assertResponseIsSuccessful();

        // Submit the form by navigating directly (simulating form submission)
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=4&category=Climate&connectivity[]=thread&min_rating=&binding=');

        // Should navigate to step 4 (compatibility) successfully
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.device-search-container');
    }

    public function testStep3LoadsWithConnectivityParameter(): void
    {
        $client = self::createClient();

        // Navigate to step 3 with connectivity parameter - should not error
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=3&category=Climate&connectivity[]=thread');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.feature-section');
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
        // Should show the Features group with capability checkboxes
        $this->assertSelectorTextContains('body', 'Features');
        $this->assertSelectorExists('input[name="capabilities[]"]');
    }

    public function testStep2ScopesCapabilitiesToCategory(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=2&category=Lights');

        $this->assertResponseIsSuccessful();
        // Lights have dimming/color devices in the dataset...
        $this->assertGreaterThan(
            0,
            $crawler->filter('input[name="capabilities[]"][value="dimming"]')->count(),
            'Lights step should offer the dimming capability'
        );
        // ...but no motion sensors, so motion_detection must be hidden.
        $this->assertCount(
            0,
            $crawler->filter('input[name="capabilities[]"][value="motion_detection"]'),
            'Lights step must not offer motion detection (zero-count for this category)'
        );
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

    public function testStep3CarriesCapabilitiesAsHiddenInputs(): void
    {
        $client = self::createClient();
        // Regression: capabilities chosen in step 2 must survive into step 3's
        // form so they reach the results redirect (previously dropped).
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=3&category=Lights&capabilities[]=dimming&capabilities[]=full_color');

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(
            0,
            $crawler->filter('#wizard-form-step3 input[type="hidden"][name="capabilities[]"][value="dimming"]')->count(),
            'Step 3 form must carry the dimming capability as a hidden input'
        );
        $this->assertGreaterThan(
            0,
            $crawler->filter('#wizard-form-step3 input[type="hidden"][name="capabilities[]"][value="full_color"]')->count(),
            'Step 3 form must carry the full_color capability as a hidden input'
        );
    }

    public function testStep4BackAndSkipLinksPreserveCapabilities(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=4&category=Lights&capabilities[]=dimming');

        $this->assertResponseIsSuccessful();
        // Both the Back (to step 3) and Skip (to results) links must keep the
        // capability selection in their query string.
        $links = $crawler->filter('#wizard-form-step4 a.wizard-btn');
        $hrefs = $links->each(fn ($node): string => (string) $node->attr('href'));
        foreach ($hrefs as $href) {
            $this->assertStringContainsString('capabilities', $href);
        }
        $this->assertNotEmpty($hrefs);
    }

    public function testStep4CarriesCapabilitiesAsHiddenInputs(): void
    {
        $client = self::createClient();
        // Capabilities must survive the final hop into the compatibility form so
        // they reach the results redirect.
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=4&category=Lights&capabilities[]=dimming&capabilities[]=full_color');

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(
            0,
            $crawler->filter('#wizard-form-step4 input[type="hidden"][name="capabilities[]"][value="dimming"]')->count(),
            'Step 4 form must carry the dimming capability as a hidden input'
        );
        $this->assertGreaterThan(
            0,
            $crawler->filter('#wizard-form-step4 input[type="hidden"][name="capabilities[]"][value="full_color"]')->count(),
            'Step 4 form must carry the full_color capability as a hidden input'
        );
    }

    public function testStep3ShowsBindingGroupsExplainerForLights(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=3&category=Lights');

        $this->assertResponseIsSuccessful();
        // The educational explainer and its illustration should render for Lights.
        $this->assertSelectorExists('.wizard-explainer');
        $this->assertSelectorExists('.wizard-illustration');
        $this->assertSelectorTextContains('.wizard-explainer', 'Binding');
        $this->assertSelectorTextContains('.wizard-explainer', 'Groups');
        // Category-specific copy (lights guide), not the generic fallback.
        $this->assertSelectorTextContains('.wizard-explainer', 'lights');
        $this->assertGreaterThan(
            0,
            $crawler->filter('.wizard-illustration')->count(),
            'Lights should render the binding illustration'
        );
    }

    public function testStep3ShowsDefaultExplainerForOtherCategory(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=3&category=Security');

        $this->assertResponseIsSuccessful();
        // Categories without bespoke content still get the default explainer
        // (no illustration), and must not error.
        $this->assertSelectorExists('.wizard-explainer');
        $this->assertSelectorNotExists('.wizard-illustration');
    }

    public function testStep3SkipPreservesCapabilitiesThroughToResults(): void
    {
        $client = self::createClient();
        // End-to-end: skipping from step 3 still forwards capabilities to the
        // device index redirect.
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard/results?category=Lights&capabilities[]=dimming');

        $this->assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('capabilities', $location);
        $this->assertStringContainsString('dimming', $location);
    }

    public function testMatchCountShownOnStep2(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=2&category=Lights');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.wizard-match-count');
    }

    public function testMatchCountHiddenOnStep1(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=1');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('.wizard-match-count');
    }

    public function testCompletedStepIsClickable(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=2&category=Lights');

        $this->assertResponseIsSuccessful();
        // The completed step 1 should render as a navigable link back to step 1.
        $this->assertSelectorExists('a.wizard-step.completed');
    }

    public function testCategoryRadioIsFocusable(): void
    {
        $client = self::createClient();
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/wizard?step=1');

        $this->assertResponseIsSuccessful();
        // Radios must exist in the DOM (not display:none-removed) for keyboard a11y.
        $this->assertGreaterThan(0, $crawler->filter('.category-card input[type="radio"]')->count());
    }
}
