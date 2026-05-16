<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Service\DclApiService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests for app:dcl:sync. Mocks DclApiService so no network calls are made,
 * and writes fixtures to a per-test temp directory so the real fixtures/
 * are never touched.
 */
final class DclSyncCommandTest extends KernelTestCase
{
    private string $tmpDir;
    private DclApiService&MockObject $dclMock;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->tmpDir = sys_get_temp_dir().'/dcl-sync-test-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->tmpDir);

        $this->dclMock = $this->createMock(DclApiService::class);
        self::getContainer()->set(DclApiService::class, $this->dclMock);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tmpDir);
        parent::tearDown();
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:dcl:sync'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCommandIsRegistered(): void
    {
        $application = new Application(self::$kernel);
        $this->assertTrue($application->has('app:dcl:sync'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailsWhenOutputDirectoryMissing(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--output-dir' => 'this/does/not/exist/'.bin2hex(random_bytes(4)),
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Output directory does not exist', $tester->getDisplay());
    }

    public function testVendorsOnlyWritesVendorsYaml(): void
    {
        $this->dclMock->expects($this->once())
            ->method('fetchAllVendors')
            ->willReturn([
                ['vendorID' => 4660, 'vendorName' => 'Acme', 'companyLegalName' => 'Acme Inc', 'vendorLandingPageURL' => 'https://acme.test'],
                ['vendorID' => 4097, 'vendorName' => 'Beta', 'companyLegalName' => null, 'vendorLandingPageURL' => null],
            ]);
        $this->dclMock->expects($this->never())->method('fetchAllModels');
        $this->dclMock->expects($this->never())->method('fetchAllCertifiedModels');

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--vendors-only' => true,
            '--output-dir' => $this->relativeOutputDir(),
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertFileExists($this->tmpDir.'/vendors.yaml');
        $this->assertFileDoesNotExist($this->tmpDir.'/products.yaml');

        $vendors = Yaml::parseFile($this->tmpDir.'/vendors.yaml');
        $this->assertCount(2, $vendors);
        $this->assertSame(4097, $vendors[0]['specId'], 'fixtures must be sorted by specId');
        $this->assertSame(4660, $vendors[1]['specId']);
        $this->assertStringContainsString('-4660', (string) $vendors[1]['slug']);
    }

    public function testProductsOnlyMergesCertificationsWhenSkippedFlagsAreOff(): void
    {
        $this->dclMock->expects($this->never())->method('fetchAllVendors');
        $this->dclMock->expects($this->never())->method('fetchAllCertifiedModels');
        $this->dclMock->expects($this->once())
            ->method('fetchAllModels')
            ->willReturn([
                [
                    'vid' => 100, 'pid' => 1,
                    'productName' => 'Bulb', 'productLabel' => 'Smart Bulb',
                    'deviceTypeId' => 269, 'partNumber' => 'ACM-1',
                ],
                [
                    'vid' => 100, 'pid' => 2,
                    'productName' => 'Plug', 'productLabel' => null, 'deviceTypeId' => 266, 'partNumber' => null,
                    'productUrl' => 'https://acme.test/plug',
                    'commissioningCustomFlow' => 0,
                ],
            ]);

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--products-only' => true,
            '--output-dir' => $this->relativeOutputDir(),
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $products = Yaml::parseFile($this->tmpDir.'/products.yaml');
        $this->assertCount(2, $products);
        $this->assertSame([100, 1], [$products[0]['vendorId'], $products[0]['productId']]);
        $this->assertArrayNotHasKey('productUrl', $products[0], 'empty URLs must be omitted');
        $this->assertSame('https://acme.test/plug', $products[1]['productUrl']);
        $this->assertSame(0, $products[1]['commissioningCustomFlow'], 'zero values must be preserved (isset, not !empty)');
    }

    public function testCertificationsOnlyWritesCertificationsYaml(): void
    {
        $this->dclMock->expects($this->once())
            ->method('fetchAllCertifiedModels')
            ->willReturn([
                '100:1' => ['vid' => 100, 'pid' => 1, 'certifiedVersions' => [10, 20], 'certificationType' => 'matter'],
                '100:2' => ['vid' => 100, 'pid' => 2, 'certifiedVersions' => [], 'certificationType' => 'matter'],
            ]);
        $this->dclMock->expects($this->once())
            ->method('fetchComplianceInfoBatch')
            ->willReturn([]);
        $this->dclMock->expects($this->never())->method('fetchAllVendors');
        $this->dclMock->expects($this->never())->method('fetchAllModels');

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--certifications-only' => true,
            '--output-dir' => $this->relativeOutputDir(),
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $certs = Yaml::parseFile($this->tmpDir.'/certifications.yaml');
        $this->assertCount(1, $certs, 'products without certified versions must be skipped');
        $this->assertSame([100, 1], [$certs[0]['vendorId'], $certs[0]['productId']]);
        $this->assertSame([10, 20], $certs[0]['certifiedVersions']);
    }

    public function testSkipCertificationsAvoidsCertApiCalls(): void
    {
        $this->dclMock->expects($this->once())->method('fetchAllVendors')->willReturn([]);
        $this->dclMock->expects($this->once())->method('fetchAllModels')->willReturn([]);
        $this->dclMock->expects($this->never())->method('fetchAllCertifiedModels');
        $this->dclMock->expects($this->never())->method('fetchComplianceInfoBatch');

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--skip-certifications' => true,
            '--output-dir' => $this->relativeOutputDir(),
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    /**
     * The command resolves output-dir relative to projectDir, so we pass
     * a path relative to the project root that points at our temp dir.
     */
    private function relativeOutputDir(): string
    {
        $projectDir = self::$kernel->getProjectDir();
        $relative = new Filesystem()->makePathRelative($this->tmpDir, $projectDir);

        return rtrim($relative, '/');
    }
}
