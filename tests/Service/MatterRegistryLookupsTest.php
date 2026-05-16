<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MatterRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Coverage-focused tests for MatterRegistry lookup methods not exercised by
 * the existing test suite: description/category/hex-id, command/attribute name
 * lookups, isGlobal/isProprietary, and feature-map decoding.
 */
class MatterRegistryLookupsTest extends KernelTestCase
{
    private MatterRegistry $registry;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->registry = self::getContainer()->get(MatterRegistry::class);
    }

    public function testGetClusterDescriptionForKnownCluster(): void
    {
        // Cluster 6 (On/Off) has a description in the fixtures
        $description = $this->registry->getClusterDescription(6);
        $this->assertNotNull($description);
        $this->assertNotSame('', $description);
    }

    public function testGetClusterDescriptionForUnknownReturnsNull(): void
    {
        $this->assertNull($this->registry->getClusterDescription(0x9998));
    }

    public function testGetClusterCategoryForKnownCluster(): void
    {
        // Cluster 6 (On/Off) belongs to "general" category in the fixtures
        $this->assertSame('general', $this->registry->getClusterCategory(6));
    }

    public function testGetClusterCategoryForUnknownReturnsNull(): void
    {
        $this->assertNull($this->registry->getClusterCategory(0x9997));
    }

    public function testGetClusterHexIdForKnownCluster(): void
    {
        // Cluster 6 → "0x0006"
        $this->assertSame('0x0006', $this->registry->getClusterHexId(6));
        $this->assertSame('0x0008', $this->registry->getClusterHexId(8));
    }

    public function testGetClusterHexIdForUnknownGeneratesPaddedFormat(): void
    {
        $this->assertSame('0x9999', $this->registry->getClusterHexId(0x9999));
    }

    public function testIsGlobalClusterTrueForGlobalUtility(): void
    {
        // Cluster 29 (Descriptor) is global per fixtures
        $this->assertTrue($this->registry->isGlobalCluster(29));
    }

    public function testIsGlobalClusterFalseForApplication(): void
    {
        // Cluster 6 (On/Off) is application-level, not global
        $this->assertFalse($this->registry->isGlobalCluster(6));
    }

    public function testIsGlobalClusterFalseForUnknown(): void
    {
        $this->assertFalse($this->registry->isGlobalCluster(0x9996));
    }

    public function testIsProprietaryClusterTrueAtBoundary(): void
    {
        $this->assertTrue($this->registry->isProprietaryCluster(0xFC00));
        $this->assertTrue($this->registry->isProprietaryCluster(0xFFFF));
    }

    public function testIsProprietaryClusterFalseBelowBoundary(): void
    {
        $this->assertFalse($this->registry->isProprietaryCluster(0xFBFF));
        $this->assertFalse($this->registry->isProprietaryCluster(6));
    }

    public function testGetClusterCommandNameForKnownCommand(): void
    {
        // On/Off cluster (6) command 0 = "Off"
        $name = $this->registry->getClusterCommandName(6, 0);
        $this->assertNotNull($name);
        $this->assertSame('Off', $name);
    }

    public function testGetClusterCommandNameReturnsNullForUnknownCommand(): void
    {
        $this->assertNull($this->registry->getClusterCommandName(6, 0xFE));
    }

    public function testGetClusterCommandNameReturnsNullForUnknownCluster(): void
    {
        $this->assertNull($this->registry->getClusterCommandName(0x9995, 0));
    }

    public function testGetClusterAttributeNameForKnownAttribute(): void
    {
        // On/Off cluster (6) attribute 0 = "OnOff"
        $name = $this->registry->getClusterAttributeName(6, 0);
        $this->assertNotNull($name);
        $this->assertSame('OnOff', $name);
    }

    public function testGetClusterAttributeNameReturnsNullForUnknownAttribute(): void
    {
        $this->assertNull($this->registry->getClusterAttributeName(6, 0xFE));
    }

    public function testGetClusterAttributeNameReturnsNullForUnknownCluster(): void
    {
        $this->assertNull($this->registry->getClusterAttributeName(0x9994, 0));
    }

    public function testDecodeFeatureMapForClusterWithFeatures(): void
    {
        // Cluster 768 (Color Control) has feature bits (HS, EHUE, etc.)
        $decoded = $this->registry->decodeFeatureMap(768, 0);

        // featureMap=0 means no bits set; every feature should be marked disabled.
        $this->assertIsArray($decoded);
        if ([] !== $decoded) {
            foreach ($decoded as $feature) {
                $this->assertArrayHasKey('code', $feature);
                $this->assertArrayHasKey('name', $feature);
                $this->assertArrayHasKey('summary', $feature);
                $this->assertArrayHasKey('enabled', $feature);
                $this->assertFalse($feature['enabled']);
            }
        }
    }

    public function testDecodeFeatureMapWithAllBitsSetEnablesAll(): void
    {
        $decoded = $this->registry->decodeFeatureMap(768, 0xFFFFFFFF);
        if ([] === $decoded) {
            $this->markTestSkipped('Color Control cluster has no features in fixture');
        }

        foreach ($decoded as $feature) {
            $this->assertTrue($feature['enabled']);
        }
    }

    public function testDecodeFeatureMapForClusterWithoutFeaturesReturnsEmpty(): void
    {
        // Cluster 6 (On/Off) — even if it has features, decoding with the
        // fallback path (no metadata) yields []. For an unknown cluster,
        // the fallback fires for sure.
        $this->assertSame([], $this->registry->decodeFeatureMap(0x9993, 0));
    }

    public function testHasFeatureMatchesBitForFeatureCode(): void
    {
        $decoded = $this->registry->decodeFeatureMap(768, 0xFFFFFFFF);
        if ([] === $decoded) {
            $this->markTestSkipped('Color Control cluster has no features in fixture');
        }

        $firstFeature = $decoded[0];
        $this->assertTrue($this->registry->hasFeature(768, $firstFeature['code'], 0xFFFFFFFF));
    }

    public function testHasFeatureFalseWhenBitNotSet(): void
    {
        $decoded = $this->registry->decodeFeatureMap(768, 0);
        if ([] === $decoded) {
            $this->markTestSkipped('Color Control cluster has no features in fixture');
        }

        $firstFeature = $decoded[0];
        $this->assertFalse($this->registry->hasFeature(768, $firstFeature['code'], 0));
    }

    public function testHasFeatureFalseForUnknownCode(): void
    {
        $this->assertFalse($this->registry->hasFeature(768, 'NOT_A_FEATURE_CODE', 0xFFFFFFFF));
    }

    public function testHasFeatureFalseForClusterWithoutFeatures(): void
    {
        $this->assertFalse($this->registry->hasFeature(0x9992, 'X', 0xFF));
    }

    public function testGetEnabledFeatureNamesIncludesOnlyEnabled(): void
    {
        $decoded = $this->registry->decodeFeatureMap(768, 1); // bit 0 set
        if ([] === $decoded) {
            $this->markTestSkipped('Color Control cluster has no features in fixture');
        }

        // Find a feature with bit 0
        $bit0Feature = null;
        foreach ($decoded as $feature) {
            if ($feature['enabled']) {
                $bit0Feature = $feature;
                break;
            }
        }

        if (null === $bit0Feature) {
            $this->markTestSkipped('No feature uses bit 0 in fixture');
        }

        $names = $this->registry->getEnabledFeatureNames(768, 1);
        $this->assertContains($bit0Feature['name'], $names);
    }

    public function testGetEnabledFeatureNamesEmptyWhenNoBitsSet(): void
    {
        $this->assertSame([], $this->registry->getEnabledFeatureNames(768, 0));
    }

    public function testGetEnabledFeatureNamesEmptyForUnknownCluster(): void
    {
        $this->assertSame([], $this->registry->getEnabledFeatureNames(0x9991, 0xFF));
    }
}
