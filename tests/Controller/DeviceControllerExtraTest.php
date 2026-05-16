<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Browser\Test\HasBrowser;

/**
 * Functional tests for DeviceController paths not covered by DeviceControllerTest:
 * the JSON autocomplete endpoint and the index filter branches.
 */
class DeviceControllerExtraTest extends KernelTestCase
{
    use HasBrowser;

    public function testAutocompleteReturnsEmptyResultsForShortQuery(): void
    {
        $this->browser()
            ->visit('/api/search?q=a')
            ->assertSuccessful()
            ->assertJson()
            ->assertContains('"results":[]');
    }

    public function testAutocompleteReturnsEmptyResultsForBlankQuery(): void
    {
        $this->browser()
            ->visit('/api/search')
            ->assertSuccessful()
            ->assertJson()
            ->assertContains('"results":[]');
    }

    public function testAutocompleteReturnsResultsForKnownVendorName(): void
    {
        // Fixture contains an "Eve Motion" product. JSON encodes forward
        // slashes as \/ by default, so match the escaped form.
        $this->browser()
            ->visit('/api/search?q=Eve')
            ->assertSuccessful()
            ->assertJson()
            ->assertContains('"results":[')
            ->assertContains('"vendor":')
            ->assertContains('"url":')
            ->assertContains('\\/device\\/');
    }

    public function testIndexConnectivityFilterIsAccepted(): void
    {
        $this->browser()
            ->visit('/?connectivity[]=wifi&connectivity[]=thread')
            ->assertSuccessful();
    }

    public function testIndexDeviceTypeFilterCoercesNumericIds(): void
    {
        $this->browser()
            ->visit('/?device_types[]=266&device_types[]=269')
            ->assertSuccessful();
    }

    public function testIndexCapabilityFilterAcceptsKnownKey(): void
    {
        $this->browser()
            ->visit('/?capabilities[]=on_off')
            ->assertSuccessful();
    }

    public function testIndexCapabilityFilterDropsUnknownKey(): void
    {
        // Unknown keys are silently filtered out; response still succeeds.
        $this->browser()
            ->visit('/?capabilities[]=not_a_real_capability_key')
            ->assertSuccessful();
    }

    public function testIndexBindingFilterAccepted(): void
    {
        $this->browser()
            ->visit('/?binding=yes')
            ->assertSuccessful();
    }
}
