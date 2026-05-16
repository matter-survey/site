<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\WizardAnalytics;
use PHPUnit\Framework\TestCase;

class WizardAnalyticsTest extends TestCase
{
    public function testDefaults(): void
    {
        $analytics = new WizardAnalytics();

        $this->assertNull($analytics->getId());
        $this->assertNull($analytics->getCategory());
        $this->assertNull($analytics->getConnectivity());
        $this->assertNull($analytics->getMinRating());
        $this->assertNull($analytics->getBinding());
        $this->assertNull($analytics->getOwnedDeviceCount());
        $this->assertFalse($analytics->isCompleted());
        $this->assertInstanceOf(\DateTimeInterface::class, $analytics->getCreatedAt());
    }

    public function testRequiredFieldsRoundTrip(): void
    {
        $analytics = (new WizardAnalytics())
            ->setSessionId('sess-abc-123')
            ->setStep(3);

        $this->assertSame('sess-abc-123', $analytics->getSessionId());
        $this->assertSame(3, $analytics->getStep());
    }

    public function testOptionalFieldsRoundTrip(): void
    {
        $analytics = (new WizardAnalytics())
            ->setSessionId('s')
            ->setStep(1)
            ->setCategory('lighting')
            ->setConnectivity(['wifi', 'thread'])
            ->setMinRating(4)
            ->setBinding('yes')
            ->setOwnedDeviceCount(7);

        $this->assertSame('lighting', $analytics->getCategory());
        $this->assertSame(['wifi', 'thread'], $analytics->getConnectivity());
        $this->assertSame(4, $analytics->getMinRating());
        $this->assertSame('yes', $analytics->getBinding());
        $this->assertSame(7, $analytics->getOwnedDeviceCount());
    }

    public function testCompletedFlag(): void
    {
        $analytics = (new WizardAnalytics())
            ->setSessionId('s')
            ->setStep(1);

        $this->assertFalse($analytics->isCompleted());

        $analytics->setCompleted(true);
        $this->assertTrue($analytics->isCompleted());
    }

    public function testCreatedAtIsMutable(): void
    {
        $when = new \DateTime('2025-03-01 12:00:00');
        $analytics = (new WizardAnalytics())
            ->setSessionId('s')
            ->setStep(1)
            ->setCreatedAt($when);

        $this->assertSame($when, $analytics->getCreatedAt());
    }

    public function testFluentInterface(): void
    {
        $analytics = new WizardAnalytics();
        $result = $analytics
            ->setSessionId('s')
            ->setStep(1)
            ->setCategory('c')
            ->setConnectivity(null)
            ->setMinRating(null)
            ->setBinding(null)
            ->setOwnedDeviceCount(null)
            ->setCompleted(false)
            ->setCreatedAt(new \DateTime());

        $this->assertSame($analytics, $result);
    }
}
