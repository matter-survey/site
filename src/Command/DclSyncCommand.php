<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Vendor;
use App\Service\DclApiService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Sync vendor and product data from the Matter Distributed Compliance Ledger (DCL) to YAML fixtures.
 */
#[AsCommand(
    name: 'app:dcl:sync',
    description: 'Fetch vendor and product data from Matter DCL API and generate YAML fixtures',
)]
class DclSyncCommand extends Command
{
    private const DEFAULT_OUTPUT_DIR = 'fixtures';

    public function __construct(
        private readonly DclApiService $dclApiService,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('vendors-only', null, InputOption::VALUE_NONE, 'Only sync vendors')
            ->addOption('products-only', null, InputOption::VALUE_NONE, 'Only sync products')
            ->addOption('certifications-only', null, InputOption::VALUE_NONE, 'Only sync certification data')
            ->addOption('skip-certifications', null, InputOption::VALUE_NONE, 'Skip fetching certification list (faster)')
            ->addOption('skip-compliance-info', null, InputOption::VALUE_NONE, 'Skip fetching detailed compliance info - certification dates, certificate IDs (faster but incomplete)')
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Output directory for YAML files', self::DEFAULT_OUTPUT_DIR);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $outputDir = $this->projectDir.'/'.$input->getOption('output-dir');

        $vendorsOnly = $input->getOption('vendors-only');
        $productsOnly = $input->getOption('products-only');
        $certificationsOnly = $input->getOption('certifications-only');
        $skipCertifications = $input->getOption('skip-certifications');
        $skipComplianceInfo = $input->getOption('skip-compliance-info');

        if (!is_dir($outputDir)) {
            $io->error(\sprintf('Output directory does not exist: %s', $outputDir));

            return Command::FAILURE;
        }

        $io->title('Matter DCL Data Sync');

        // Fetch certification data if needed
        $certifiedModels = [];
        if (!$skipCertifications && ($certificationsOnly || (!$vendorsOnly && !$productsOnly))) {
            $certifiedModels = $this->fetchCertifications($io);
        }

        // Fetch detailed compliance info (certification dates, etc.) unless skipped
        $complianceInfo = [];
        if (!$skipComplianceInfo && \count($certifiedModels) > 0) {
            $complianceInfo = $this->fetchComplianceInfo($io, $certifiedModels);
        }

        // Sync vendors
        if (!$productsOnly && !$certificationsOnly) {
            $this->syncVendors($io, $outputDir);
        }

        // Sync products (with certification data merged in)
        if (!$vendorsOnly && !$certificationsOnly) {
            $this->syncProducts($io, $outputDir, $certifiedModels, $complianceInfo);
        }

        // Export certifications separately if requested
        if ($certificationsOnly) {
            $this->exportCertifications($io, $outputDir, $certifiedModels);
        }

        $io->success('DCL sync complete!');

        return Command::SUCCESS;
    }

    /**
     * @return array<string, array{vid: int, pid: int, certifiedVersions: array<int>, certificationType: string}>
     */
    private function fetchCertifications(SymfonyStyle $io): array
    {
        $io->section('Fetching certification data from DCL API...');

        $certifiedModels = $this->dclApiService->fetchAllCertifiedModels();
        $io->info(\sprintf('Fetched certification data for %d products', \count($certifiedModels)));

        return $certifiedModels;
    }

    /**
     * Fetch detailed compliance info for all certified models.
     * This is slow as it requires one API call per certified model.
     *
     * @param array<string, array{vid: int, pid: int, certifiedVersions: array<int>, certificationType: string}> $certifiedModels
     *
     * @return array<string, array{date: string, cDCertificateId: string, softwareVersionString: string}>
     */
    private function fetchComplianceInfo(SymfonyStyle $io, array $certifiedModels): array
    {
        $io->section('Fetching detailed compliance info from DCL API...');
        $io->warning('This will make ~'.\count($certifiedModels).' API calls and may take several minutes.');

        // Prepare batch request data
        $batchData = [];
        foreach ($certifiedModels as $key => $cert) {
            if (\count($cert['certifiedVersions']) > 0) {
                // Use the first (oldest) certified version
                $batchData[] = [
                    'vid' => $cert['vid'],
                    'pid' => $cert['pid'],
                    'softwareVersion' => $cert['certifiedVersions'][0],
                    'certificationType' => $cert['certificationType'],
                ];
            }
        }

        $progressBar = $io->createProgressBar(\count($batchData));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');

        $complianceInfo = $this->dclApiService->fetchComplianceInfoBatch(
            $batchData,
            function ($processed, $total) use ($progressBar) {
                $progressBar->setProgress($processed);
            }
        );

        $progressBar->finish();
        $io->newLine(2);

        $io->info(\sprintf('Fetched compliance info for %d products', \count($complianceInfo)));

        return $complianceInfo;
    }

    /**
     * @param array<string, array{vid: int, pid: int, certifiedVersions: array<int>, certificationType: string}> $certifiedModels
     */
    private function exportCertifications(SymfonyStyle $io, string $outputDir, array $certifiedModels): void
    {
        $io->section('Exporting certification data...');

        $fixtures = [];
        foreach ($certifiedModels as $cert) {
            if (\count($cert['certifiedVersions']) > 0) {
                $fixtures[] = [
                    'vendorId' => $cert['vid'],
                    'productId' => $cert['pid'],
                    'certifiedVersions' => $cert['certifiedVersions'],
                    'certificationType' => $cert['certificationType'],
                ];
            }
        }

        // Sort by vendorId, then productId
        usort($fixtures, function ($a, $b) {
            if ($a['vendorId'] !== $b['vendorId']) {
                return $a['vendorId'] <=> $b['vendorId'];
            }

            return $a['productId'] <=> $b['productId'];
        });

        // Write YAML file
        $yamlContent = "# Matter Certified Software Versions from DCL\n";
        $yamlContent .= "# Auto-generated by app:dcl:sync - do not edit manually\n";
        $yamlContent .= "# Source: https://on.dcl.csa-iot.org/dcl/compliance/certified-models\n";
        $yamlContent .= '# Generated: '.date('Y-m-d H:i:s')."\n\n";
        $yamlContent .= Yaml::dump($fixtures, 3, 2);

        $certsFile = $outputDir.'/certifications.yaml';
        file_put_contents($certsFile, $yamlContent);

        $io->success(\sprintf('Wrote %d certifications to %s', \count($fixtures), $certsFile));
    }

    private function syncVendors(SymfonyStyle $io, string $outputDir): void
    {
        $io->section('Fetching vendors from DCL API...');

        $vendors = $this->dclApiService->fetchAllVendors();
        $io->info(\sprintf('Fetched %d vendors', \count($vendors)));

        // Transform to fixture format
        $fixtures = [];
        foreach ($vendors as $vendor) {
            $name = $vendor['vendorName'] ?? '';
            $specId = $vendor['vendorID'];

            // Generate unique slug with specId suffix (e.g., 'govee-4947')
            $baseSlug = Vendor::generateSlug($name, $specId);
            $slug = $baseSlug.'-'.$specId;

            $fixtures[] = [
                'specId' => $specId,
                'name' => $name,
                'slug' => $slug,
                'companyLegalName' => $vendor['companyLegalName'] ?? null,
                'vendorLandingPageURL' => $vendor['vendorLandingPageURL'] ?? null,
            ];
        }

        // Sort by specId for consistent output
        usort($fixtures, fn ($a, $b) => $a['specId'] <=> $b['specId']);

        // Write YAML file
        $yamlContent = "# Matter Vendors from DCL (Distributed Compliance Ledger)\n";
        $yamlContent .= "# Auto-generated by app:dcl:sync - do not edit manually\n";
        $yamlContent .= "# Source: https://on.dcl.csa-iot.org/dcl/vendorinfo/vendors\n";
        $yamlContent .= '# Generated: '.date('Y-m-d H:i:s')."\n\n";
        $yamlContent .= Yaml::dump($fixtures, 2, 2);

        $vendorsFile = $outputDir.'/vendors.yaml';
        file_put_contents($vendorsFile, $yamlContent);

        $io->success(\sprintf('Wrote %d vendors to %s', \count($fixtures), $vendorsFile));
    }

    /**
     * @param array<string, array{vid: int, pid: int, certifiedVersions: array<int>, certificationType: string}> $certifiedModels
     * @param array<string, array{date: string, cDCertificateId: string, softwareVersionString: string}>         $complianceInfo
     */
    private function syncProducts(SymfonyStyle $io, string $outputDir, array $certifiedModels, array $complianceInfo = []): void
    {
        $io->section('Fetching products from DCL API...');

        $models = $this->dclApiService->fetchAllModels();
        $io->info(\sprintf('Fetched %d products', \count($models)));

        // Transform to fixture format
        $fixtures = [];
        foreach ($models as $model) {
            $vid = $model['vid'];
            $pid = $model['pid'];

            $fixture = [
                'vendorId' => $vid,
                'productId' => $pid,
                'productName' => $model['productName'] ?? null,
                'productLabel' => $model['productLabel'] ?? null,
                'deviceTypeId' => $model['deviceTypeId'] ?? null,
                'partNumber' => $model['partNumber'] ?? null,
            ];

            // Only include non-empty URLs
            if (!empty($model['productUrl'])) {
                $fixture['productUrl'] = $model['productUrl'];
            }
            if (!empty($model['supportUrl'])) {
                $fixture['supportUrl'] = $model['supportUrl'];
            }
            if (!empty($model['userManualUrl'])) {
                $fixture['userManualUrl'] = $model['userManualUrl'];
            }
            if (!empty($model['commissioningCustomFlowUrl'])) {
                $fixture['commissioningCustomFlowUrl'] = $model['commissioningCustomFlowUrl'];
            }
            if (!empty($model['maintenanceUrl'])) {
                $fixture['maintenanceUrl'] = $model['maintenanceUrl'];
            }
            if (!empty($model['commissioningFallbackUrl'])) {
                $fixture['commissioningFallbackUrl'] = $model['commissioningFallbackUrl'];
            }
            if (!empty($model['lsfUrl'])) {
                $fixture['lsfUrl'] = $model['lsfUrl'];
            }

            // Discovery and commissioning fields (use isset to preserve 0 values)
            if (isset($model['discoveryCapabilitiesBitmask'])) {
                $fixture['discoveryCapabilitiesBitmask'] = $model['discoveryCapabilitiesBitmask'];
            }
            if (isset($model['commissioningCustomFlow'])) {
                $fixture['commissioningCustomFlow'] = $model['commissioningCustomFlow'];
            }
            if (isset($model['commissioningModeInitialStepsHint'])) {
                $fixture['commissioningModeInitialStepsHint'] = $model['commissioningModeInitialStepsHint'];
            }
            if (!empty($model['commissioningModeInitialStepsInstruction'])) {
                $fixture['commissioningModeInitialStepsInstruction'] = $model['commissioningModeInitialStepsInstruction'];
            }
            if (isset($model['commissioningModeSecondaryStepsHint'])) {
                $fixture['commissioningModeSecondaryStepsHint'] = $model['commissioningModeSecondaryStepsHint'];
            }
            if (!empty($model['commissioningModeSecondaryStepsInstruction'])) {
                $fixture['commissioningModeSecondaryStepsInstruction'] = $model['commissioningModeSecondaryStepsInstruction'];
            }

            // Factory reset fields
            if (isset($model['factoryResetStepsHint'])) {
                $fixture['factoryResetStepsHint'] = $model['factoryResetStepsHint'];
            }
            if (!empty($model['factoryResetStepsInstruction'])) {
                $fixture['factoryResetStepsInstruction'] = $model['factoryResetStepsInstruction'];
            }

            // ICD (Intermittently Connected Device) fields
            if (isset($model['icdUserActiveModeTriggerHint'])) {
                $fixture['icdUserActiveModeTriggerHint'] = $model['icdUserActiveModeTriggerHint'];
            }
            if (!empty($model['icdUserActiveModeTriggerInstruction'])) {
                $fixture['icdUserActiveModeTriggerInstruction'] = $model['icdUserActiveModeTriggerInstruction'];
            }

            // LSF (Label/Setup File)
            if (isset($model['lsfRevision'])) {
                $fixture['lsfRevision'] = $model['lsfRevision'];
            }

            // Add certified software versions from certification data
            $certKey = $vid.':'.$pid;
            if (isset($certifiedModels[$certKey]) && \count($certifiedModels[$certKey]['certifiedVersions']) > 0) {
                $fixture['certifiedSoftwareVersions'] = $certifiedModels[$certKey]['certifiedVersions'];
            }

            // Add compliance info (certification date, certificate ID) if available
            if (isset($complianceInfo[$certKey])) {
                $info = $complianceInfo[$certKey];
                if (!empty($info['date'])) {
                    $fixture['certificationDate'] = $info['date'];
                }
                if (!empty($info['cDCertificateId'])) {
                    $fixture['certificateId'] = $info['cDCertificateId'];
                }
                if (!empty($info['softwareVersionString'])) {
                    $fixture['softwareVersionString'] = $info['softwareVersionString'];
                }
            }

            $fixtures[] = $fixture;
        }

        // Sort by vendorId, then productId for consistent output
        usort($fixtures, function ($a, $b) {
            if ($a['vendorId'] !== $b['vendorId']) {
                return $a['vendorId'] <=> $b['vendorId'];
            }

            return $a['productId'] <=> $b['productId'];
        });

        // Write YAML file
        $yamlContent = "# Matter Products from DCL (Distributed Compliance Ledger)\n";
        $yamlContent .= "# Auto-generated by app:dcl:sync - do not edit manually\n";
        $yamlContent .= "# Source: https://on.dcl.csa-iot.org/dcl/model/models\n";
        $yamlContent .= "# Certification data: https://on.dcl.csa-iot.org/dcl/compliance/certified-models\n";
        $yamlContent .= '# Generated: '.date('Y-m-d H:i:s')."\n\n";
        $yamlContent .= Yaml::dump($fixtures, 3, 2);

        $productsFile = $outputDir.'/products.yaml';
        file_put_contents($productsFile, $yamlContent);

        // Count products with certification data and compliance info
        $certifiedCount = \count(array_filter($fixtures, fn ($f) => isset($f['certifiedSoftwareVersions'])));
        $withDatesCount = \count(array_filter($fixtures, fn ($f) => isset($f['certificationDate'])));

        $message = \sprintf('Wrote %d products to %s (%d with certification data', \count($fixtures), $productsFile, $certifiedCount);
        if ($withDatesCount > 0) {
            $message .= \sprintf(', %d with certification dates', $withDatesCount);
        }
        $message .= ')';

        $io->success($message);
    }
}
