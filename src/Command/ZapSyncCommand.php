<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
    private const ZCL_BASE_URL = 'https://raw.githubusercontent.com/project-chip/connectedhomeip/master/src/app/zap-templates/zcl';
    private const ZCL_JSON_URL = self::ZCL_BASE_URL . '/zcl.json';
    private const ZCL_XML_BASE = self::ZCL_BASE_URL . '/data-model/chip/';
    private const DEFAULT_OUTPUT_FILE = 'fixtures/clusters.yaml';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be updated without making changes')
            ->addOption('cluster', 'c', InputOption::VALUE_REQUIRED, 'Sync only a specific cluster by ID (decimal)')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path', self::DEFAULT_OUTPUT_FILE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $singleClusterId = $input->getOption('cluster');
        $outputFile = $this->projectDir . '/' . $input->getOption('output');

        $io->title('Matter ZAP Cluster Data Sync');

        if ($dryRun) {
            $io->warning('DRY RUN - No changes will be made to fixtures');
        }

        // Load existing clusters from YAML
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

        // Fetch list of XML files from zcl.json
        $io->section('Fetching cluster file list from GitHub...');
        $clusterFiles = $this->fetchClusterFileList($io);
        if (empty($clusterFiles)) {
            $io->error('Failed to fetch cluster file list');

            return Command::FAILURE;
        }
        $io->info(\sprintf('Found %d cluster XML files', \count($clusterFiles)));

        // Process each XML file
        $updatedCount = 0;
        $skippedCount = 0;

        $io->section('Processing cluster XML files...');
        $io->progressStart(\count($clusterFiles));

        foreach ($clusterFiles as $file) {
            // Skip non-cluster files
            if (!str_ends_with($file, '-cluster.xml')) {
                $io->progressAdvance();
                continue;
            }

            $clusterData = $this->fetchAndParseClusterXml($file);
            if ($clusterData === null) {
                $skippedCount++;
                $io->progressAdvance();
                continue;
            }

            // Filter by single cluster if specified
            if ($singleClusterId !== null && $clusterData['id'] !== (int) $singleClusterId) {
                $io->progressAdvance();
                continue;
            }

            // Check if cluster exists in our fixtures
            if (!isset($clusterMap[$clusterData['id']])) {
                $skippedCount++;
                $io->progressAdvance();
                continue;
            }

            // Update the cluster in the array
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

            $updatedCount++;
            $io->progressAdvance();
        }

        $io->progressFinish();

        // Write updated YAML
        if (!$dryRun) {
            $io->section('Writing updated fixtures...');

            $yamlContent = "# Matter Cluster Definitions\n";
            $yamlContent .= "# Based on Matter 1.4 Specification\n";
            $yamlContent .= "# Attributes, commands, and features synced from connectedhomeip ZAP XML files\n";
            $yamlContent .= "# Last ZAP sync: " . date('Y-m-d H:i:s') . "\n\n";
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

    /**
     * @return array<string>
     */
    private function fetchClusterFileList(SymfonyStyle $io): array
    {
        try {
            $response = $this->httpClient->request('GET', self::ZCL_JSON_URL);
            $data = json_decode($response->getContent(), true);

            return $data['xmlFile'] ?? [];
        } catch (\Exception $e) {
            $io->error('Failed to fetch zcl.json: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @return array{id: int, name: string, attributes: array, commands: array, features: array}|null
     */
    private function fetchAndParseClusterXml(string $filename): ?array
    {
        $url = self::ZCL_XML_BASE . $filename;

        try {
            $response = $this->httpClient->request('GET', $url);
            $xml = simplexml_load_string($response->getContent());
            if ($xml === false) {
                return null;
            }

            // Find the cluster element - look for cluster element with children (the actual definition)
            // Skip cluster elements that are just references (have only 'code' attribute)
            $clusterNode = null;
            $clusters = $xml->xpath('//cluster');
            foreach ($clusters as $candidate) {
                // The actual cluster definition has a <code> child element, not just a 'code' attribute
                if (isset($candidate->code) || isset($candidate->name)) {
                    $clusterNode = $candidate;
                    break;
                }
            }

            if ($clusterNode === null) {
                return null;
            }

            // Get cluster ID from <code> child element or 'code' attribute
            $codeValue = (string) ($clusterNode->code ?? $clusterNode['code'] ?? '');
            if (empty($codeValue)) {
                return null;
            }

            $clusterId = $this->parseHexOrDecimal($codeValue);
            $clusterName = (string) ($clusterNode->name ?? $clusterNode['name'] ?? '');

            // Parse features
            $features = $this->parseFeatures($clusterNode);

            // Parse attributes
            $attributes = $this->parseAttributes($clusterNode);

            // Parse commands
            $commands = $this->parseCommands($clusterNode);

            return [
                'id' => $clusterId,
                'name' => $clusterName,
                'features' => $features,
                'attributes' => $attributes,
                'commands' => $commands,
            ];
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array<array{bit: int, code: string, name: string, summary: string|null}>
     */
    private function parseFeatures(\SimpleXMLElement $clusterNode): array
    {
        $features = [];

        // Features can be in different locations
        $featureNodes = $clusterNode->features->feature ?? $clusterNode->xpath('.//feature') ?? [];

        foreach ($featureNodes as $feature) {
            $features[] = [
                'bit' => (int) ($feature['bit'] ?? 0),
                'code' => (string) ($feature['code'] ?? ''),
                'name' => (string) ($feature['name'] ?? ''),
                'summary' => (string) ($feature['summary'] ?? $feature->description ?? null) ?: null,
            ];
        }

        // Sort by bit position
        usort($features, fn ($a, $b) => $a['bit'] <=> $b['bit']);

        return $features;
    }

    /**
     * @return array<array{code: int, name: string, type: string, writable: bool, optional: bool}>
     */
    private function parseAttributes(\SimpleXMLElement $clusterNode): array
    {
        $attributes = [];

        // Attributes can be direct children or under attributes element
        $attrNodes = $clusterNode->attribute ?? $clusterNode->attributes->attribute ?? [];

        foreach ($attrNodes as $attr) {
            $code = (string) ($attr['code'] ?? $attr['id'] ?? '');
            if (empty($code)) {
                continue;
            }

            $attributes[] = [
                'code' => $this->parseHexOrDecimal($code),
                'name' => (string) ($attr['name'] ?? $attr->name ?? ''),
                'type' => (string) ($attr['type'] ?? $attr->type ?? 'unknown'),
                'writable' => $this->parseBoolean($attr['writable'] ?? 'false'),
                'optional' => $this->parseBoolean($attr['optional'] ?? 'false'),
            ];
        }

        // Sort by code
        usort($attributes, fn ($a, $b) => $a['code'] <=> $b['code']);

        return $attributes;
    }

    /**
     * @return array<array{code: int, name: string, direction: string, optional: bool, parameters: array}>
     */
    private function parseCommands(\SimpleXMLElement $clusterNode): array
    {
        $commands = [];

        // Commands can be direct children or under commands element
        $cmdNodes = $clusterNode->command ?? $clusterNode->commands->command ?? [];

        foreach ($cmdNodes as $cmd) {
            $code = (string) ($cmd['code'] ?? $cmd['id'] ?? '');
            if (empty($code)) {
                continue;
            }

            // Determine direction from source attribute or default to client→server
            $source = (string) ($cmd['source'] ?? 'client');
            $direction = $source === 'client' ? 'client→server' : 'server→client';

            // Parse command parameters
            $parameters = [];
            foreach ($cmd->arg ?? $cmd->field ?? [] as $param) {
                $parameters[] = [
                    'name' => (string) ($param['name'] ?? $param->name ?? ''),
                    'type' => (string) ($param['type'] ?? $param->type ?? 'unknown'),
                ];
            }

            $commands[] = [
                'code' => $this->parseHexOrDecimal($code),
                'name' => (string) ($cmd['name'] ?? $cmd->name ?? ''),
                'direction' => $direction,
                'optional' => $this->parseBoolean($cmd['optional'] ?? 'false'),
                'parameters' => $parameters,
            ];
        }

        // Sort by code
        usort($commands, fn ($a, $b) => $a['code'] <=> $b['code']);

        return $commands;
    }

    private function parseHexOrDecimal(string $value): int
    {
        $value = trim($value);
        if (str_starts_with(strtolower($value), '0x')) {
            return (int) hexdec($value);
        }

        return (int) $value;
    }

    private function parseBoolean(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        $str = strtolower(trim((string) $value));

        return \in_array($str, ['true', '1', 'yes'], true);
    }
}
