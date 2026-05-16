<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ZclParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Sync cluster attributes, commands, and features from Matter SDK ZAP XML files.
 *
 * ZAP (ZCL Advanced Platform) XML files contain the authoritative Matter specification data
 * for all clusters, including their attributes, commands, features, and enumerations.
 *
 * Source: https://github.com/project-chip/connectedhomeip/tree/master/src/app/zap-templates/zcl/data-model/chip
 */
#[AsCommand(
    name: 'app:zap:sync',
    description: 'Fetch cluster details (attributes, commands, features) from Matter SDK ZAP XML files and update fixtures',
)]
class ZapSyncCommand extends Command
{
    private const string DEFAULT_REF = 'master';
    private const string DEFAULT_OUTPUT_FILE = 'fixtures/clusters.yaml';

    public function __construct(
        private readonly ZclParser $parser,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be updated without making changes')
            ->addOption('cluster', 'c', InputOption::VALUE_REQUIRED, 'Sync only a specific cluster by ID (decimal)')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path', self::DEFAULT_OUTPUT_FILE)
            ->addOption('ref', 'r', InputOption::VALUE_REQUIRED, 'Git ref (tag, branch, or SHA) to sync from', self::DEFAULT_REF);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $span = \App\Observability\Tracer::start('zap.sync');
        $scope = $span->activate();

        try {
            return $this->doExecute($input, $output, $span);
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    private function doExecute(InputInterface $input, OutputInterface $output, \OpenTelemetry\API\Trace\SpanInterface $span): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $singleClusterId = $input->getOption('cluster');
        $outputFile = $this->projectDir.'/'.$input->getOption('output');
        $ref = $input->getOption('ref') ?: self::DEFAULT_REF;
        $span->setAttribute('zap.ref', $ref);

        $io->title('Matter ZAP Cluster Data Sync');
        $io->info(\sprintf('Source ref: %s', $ref));

        if ($dryRun) {
            $io->warning('DRY RUN - No changes will be made to fixtures');
        }

        $io->section('Loading existing clusters from fixtures...');
        if (!file_exists($outputFile)) {
            $io->error(\sprintf('Clusters YAML file not found: %s', $outputFile));

            return Command::FAILURE;
        }

        $existingClusters = Yaml::parseFile($outputFile);
        $clusterMap = [];
        foreach ($existingClusters as $index => $cluster) {
            $clusterMap[(int) $cluster['id']] = $index;
        }
        $io->info(\sprintf('Found %d existing clusters in fixtures', \count($clusterMap)));

        $io->section('Fetching cluster file list from GitHub...');
        try {
            $clusterFiles = $this->parser->fetchClusterFileList($ref);
        } catch (\Throwable $e) {
            $io->error('Failed to fetch zcl.json: '.$e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $clusterFiles) {
            $io->error('No cluster XML files listed in zcl.json');

            return Command::FAILURE;
        }
        $io->info(\sprintf('Found %d cluster XML files', \count($clusterFiles)));
        $span->setAttribute('zap.cluster_count', \count($clusterFiles));

        $updatedCount = 0;
        $skippedCount = 0;

        $io->section('Processing cluster XML files...');
        $io->progressStart(\count($clusterFiles));

        foreach ($clusterFiles as $file) {
            $clustersInFile = $this->parser->fetchAndParseClusterXml($file, $ref);
            if ([] === $clustersInFile) {
                $io->progressAdvance();
                continue;
            }

            foreach ($clustersInFile as $clusterData) {
                if (null !== $singleClusterId && $clusterData['id'] !== (int) $singleClusterId) {
                    continue;
                }

                if (!isset($clusterMap[$clusterData['id']])) {
                    ++$skippedCount;
                    continue;
                }

                $index = $clusterMap[$clusterData['id']];

                if (!empty($clusterData['attributes'])) {
                    $existingClusters[$index]['attributes'] = $clusterData['attributes'];
                }
                if (!empty($clusterData['commands'])) {
                    $existingClusters[$index]['commands'] = $clusterData['commands'];
                }
                if (!empty($clusterData['features'])) {
                    $existingClusters[$index]['features'] = $clusterData['features'];
                }
                if (!empty($clusterData['apiMaturity'])) {
                    $existingClusters[$index]['apiMaturity'] = $clusterData['apiMaturity'];
                } else {
                    unset($existingClusters[$index]['apiMaturity']);
                }
                if (isset($clusterData['clusterRevision'])) {
                    $existingClusters[$index]['clusterRevision'] = $clusterData['clusterRevision'];
                } else {
                    unset($existingClusters[$index]['clusterRevision']);
                }

                ++$updatedCount;
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        if (!$dryRun) {
            $io->section('Writing updated fixtures...');

            $yamlContent = "# Matter Cluster Definitions\n";
            $yamlContent .= "# Attributes, commands, and features synced from connectedhomeip ZAP XML files\n";
            $yamlContent .= \sprintf("# Source ref: %s\n", $ref);
            $yamlContent .= '# Last ZAP sync: '.date('Y-m-d H:i:s')."\n\n";
            $yamlContent .= Yaml::dump($existingClusters, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

            file_put_contents($outputFile, $yamlContent);
            $io->info(\sprintf('Written to %s', $outputFile));
        }

        $io->newLine();
        $io->success(\sprintf(
            'ZAP sync complete! Updated: %d clusters, Skipped: %d (not in fixtures or non-cluster files)',
            $updatedCount,
            $skippedCount
        ));

        return Command::SUCCESS;
    }
}
