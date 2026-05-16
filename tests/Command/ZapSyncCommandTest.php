<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ZapSyncCommandTest extends KernelTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('app:zap:sync');
        $this->commandTester = new CommandTester($command);
    }

    public function testExecuteDryRun(): void
    {
        $this->commandTester->execute(['--dry-run' => true]);

        $output = $this->commandTester->getDisplay();

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('ZAP sync complete', $output);
        $this->assertStringContainsString('cluster XML files', $output);
        $this->assertStringContainsString('existing clusters in fixtures', $output);
    }

    public function testExecuteSingleClusterDryRun(): void
    {
        // Test syncing a single cluster (On/Off = ID 6)
        $this->commandTester->execute([
            '--dry-run' => true,
            '--cluster' => '6',
        ]);

        $output = $this->commandTester->getDisplay();

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('DRY RUN', $output);
    }

    public function testCommandIsRegistered(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $this->assertTrue($application->has('app:zap:sync'));
    }

    public function testExecuteAcceptsRefOption(): void
    {
        // Pinned Matter release tag — frozen in upstream, safe as a fixture for the
        // ref-plumbing path. Asserts the ref appears in the output header so a typo
        // in URL construction would be caught.
        $this->commandTester->execute([
            '--dry-run' => true,
            '--cluster' => '6',
            '--ref' => 'v1.4.2.0',
        ]);

        $output = $this->commandTester->getDisplay();

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('v1.4.2.0', $output);
        $this->assertStringContainsString('ZAP sync complete', $output);
    }
}
