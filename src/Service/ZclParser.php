<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches and parses Matter ZAP XML cluster definitions from the connectedhomeip
 * SDK. Pure data layer — no logging, no progress reporting. Callers decide how
 * to surface errors. Network failures on the file-list fetch throw
 * RuntimeException; per-cluster XML failures return an empty list.
 */
final readonly class ZclParser
{
    private const string REPO_BASE = 'https://raw.githubusercontent.com/project-chip/connectedhomeip';

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function zclBaseUrl(string $ref): string
    {
        return \sprintf('%s/%s/src/app/zap-templates/zcl', self::REPO_BASE, $ref);
    }

    /**
     * @return list<string>
     */
    public function fetchClusterFileList(string $ref): array
    {
        $response = $this->httpClient->request('GET', $this->zclBaseUrl($ref).'/zcl.json');
        $data = json_decode($response->getContent(), true);

        return $data['xmlFile'] ?? [];
    }

    /**
     * Parse all primary cluster definitions from a ZAP XML file.
     *
     * Some files (e.g. concentration-measurement-cluster.xml, resource-monitoring-cluster.xml)
     * define multiple sibling clusters. Files without any primary cluster definition
     * (types-only, extensions, etc.) return an empty list.
     *
     * @return list<array{id: int, name: string, attributes: array, commands: array, features: array, description?: string, apiMaturity?: string, clusterRevision?: int}>
     */
    public function fetchAndParseClusterXml(string $filename, string $ref): array
    {
        $url = $this->zclBaseUrl($ref).'/data-model/chip/'.$filename;

        try {
            $response = $this->httpClient->request('GET', $url);
            $xml = simplexml_load_string($response->getContent());
            if (false === $xml) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        $results = [];
        // Primary cluster definitions have <code> and <name> as child elements.
        // Self-closing references like <cluster code="0x0006"/> inside enums/bitmaps are skipped.
        foreach ($xml->xpath('//cluster') ?: [] as $candidate) {
            if ((!property_exists($candidate, 'code') || null === $candidate->code) && (!property_exists($candidate, 'name') || null === $candidate->name)) {
                continue;
            }

            $codeValue = trim((string) $candidate->code);
            if ('' === $codeValue) {
                continue;
            }

            $entry = [
                'id' => $this->parseHexOrDecimal($codeValue),
                'name' => trim((string) $candidate->name),
                'features' => $this->parseFeatures($candidate),
                'attributes' => $this->parseAttributes($candidate),
                'commands' => $this->parseCommands($candidate),
            ];

            $description = trim((string) ($candidate->description ?? ''));
            if ('' !== $description) {
                $entry['description'] = $description;
            }

            $apiMaturity = trim((string) ($candidate['apiMaturity'] ?? ''));
            if ('' !== $apiMaturity) {
                $entry['apiMaturity'] = $apiMaturity;
            }

            $clusterRevision = $this->parseClusterRevision($candidate);
            if (null !== $clusterRevision) {
                $entry['clusterRevision'] = $clusterRevision;
            }

            $results[] = $entry;
        }

        return $results;
    }

    /**
     * @return list<array{bit: int, code: string, name: string, summary: string|null, apiMaturity?: string}>
     */
    private function parseFeatures(\SimpleXMLElement $clusterNode): array
    {
        if (!property_exists($clusterNode->features, 'feature') || null === $clusterNode->features->feature) {
            return [];
        }

        $features = [];
        foreach ($clusterNode->features->feature as $feature) {
            $summary = (string) ($feature['summary'] ?? '');
            if ('' === $summary) {
                $summary = trim((string) ($feature->description ?? ''));
            }

            $entry = [
                'bit' => (int) ($feature['bit'] ?? 0),
                'code' => (string) ($feature['code'] ?? ''),
                'name' => (string) ($feature['name'] ?? ''),
                'summary' => '' !== $summary ? $summary : null,
            ];

            $apiMaturity = trim((string) ($feature['apiMaturity'] ?? ''));
            if ('' !== $apiMaturity) {
                $entry['apiMaturity'] = $apiMaturity;
            }

            $features[] = $entry;
        }

        usort($features, fn (array $a, array $b): int => $a['bit'] <=> $b['bit']);

        return $features;
    }

    /**
     * @return list<array{
     *     code: int, name: string, type: string, writable: bool, optional: bool,
     *     isNullable?: bool, default?: string, min?: string, max?: string,
     *     apiMaturity?: string, description?: string, access?: list<array{op: string, privilege: string}>
     * }>
     */
    private function parseAttributes(\SimpleXMLElement $clusterNode): array
    {
        $attributes = [];
        $attrNodes = $clusterNode->attribute ?? $clusterNode->attributes->attribute ?? [];

        foreach ($attrNodes as $attr) {
            $side = (string) ($attr['side'] ?? 'server');
            if ('server' !== $side) {
                continue;
            }

            $code = (string) ($attr['code'] ?? $attr['id'] ?? '');
            if ('' === $code) {
                continue;
            }

            $entry = [
                'code' => $this->parseHexOrDecimal($code),
                'name' => (string) ($attr['name'] ?? $attr->name ?? ''),
                'type' => (string) ($attr['type'] ?? $attr->type ?? 'unknown'),
                'writable' => $this->parseBoolean($attr['writable'] ?? 'false'),
                'optional' => $this->parseBoolean($attr['optional'] ?? 'false'),
            ];

            if ($this->parseBoolean($attr['isNullable'] ?? 'false')) {
                $entry['isNullable'] = true;
            }
            foreach (['default', 'min', 'max'] as $key) {
                $value = trim((string) ($attr[$key] ?? ''));
                if ('' !== $value) {
                    $entry[$key] = $value;
                }
            }
            $apiMaturity = trim((string) ($attr['apiMaturity'] ?? ''));
            if ('' !== $apiMaturity) {
                $entry['apiMaturity'] = $apiMaturity;
            }
            $description = trim((string) ($attr->description ?? ''));
            if ('' !== $description) {
                $entry['description'] = $description;
            }
            $access = $this->parseAccess($attr);
            if ([] !== $access) {
                $entry['access'] = $access;
            }

            $attributes[] = $entry;
        }

        usort($attributes, fn (array $a, array $b): int => $a['code'] <=> $b['code']);

        return $attributes;
    }

    /**
     * @return list<array{code: int, name: string, direction: string, optional: bool, parameters: list<array{name: string, type: string}>, apiMaturity?: string, description?: string, access?: list<array{op: string, privilege: string}>}>
     */
    private function parseCommands(\SimpleXMLElement $clusterNode): array
    {
        $commands = [];
        $cmdNodes = $clusterNode->command ?? $clusterNode->commands->command ?? [];

        foreach ($cmdNodes as $cmd) {
            $code = (string) ($cmd['code'] ?? $cmd['id'] ?? '');
            if ('' === $code) {
                continue;
            }

            $source = (string) ($cmd['source'] ?? 'client');
            $direction = 'client' === $source ? 'client→server' : 'server→client';

            $parameters = [];
            foreach ($cmd->arg ?? $cmd->field ?? [] as $param) {
                $parameters[] = [
                    'name' => (string) ($param['name'] ?? $param->name ?? ''),
                    'type' => (string) ($param['type'] ?? $param->type ?? 'unknown'),
                ];
            }

            $entry = [
                'code' => $this->parseHexOrDecimal($code),
                'name' => (string) ($cmd['name'] ?? $cmd->name ?? ''),
                'direction' => $direction,
                'optional' => $this->parseBoolean($cmd['optional'] ?? 'false'),
                'parameters' => $parameters,
            ];

            $apiMaturity = trim((string) ($cmd['apiMaturity'] ?? ''));
            if ('' !== $apiMaturity) {
                $entry['apiMaturity'] = $apiMaturity;
            }
            $description = trim((string) ($cmd->description ?? ''));
            if ('' !== $description) {
                $entry['description'] = $description;
            }
            $access = $this->parseAccess($cmd);
            if ([] !== $access) {
                $entry['access'] = $access;
            }

            $commands[] = $entry;
        }

        usort($commands, fn (array $a, array $b): int => $a['code'] <=> $b['code']);

        return $commands;
    }

    /**
     * Read the ClusterRevision (global attribute 0xFFFD) from the cluster node.
     */
    private function parseClusterRevision(\SimpleXMLElement $clusterNode): ?int
    {
        foreach ($clusterNode->globalAttribute ?? [] as $ga) {
            $code = trim((string) ($ga['code'] ?? ''));
            if ('' === $code) {
                continue;
            }
            if (65533 !== $this->parseHexOrDecimal($code)) {
                continue;
            }
            $value = trim((string) ($ga['value'] ?? ''));
            if ('' === $value) {
                continue;
            }

            return $this->parseHexOrDecimal($value);
        }

        return null;
    }

    /**
     * @return list<array{op: string, privilege: string}>
     */
    private function parseAccess(\SimpleXMLElement $node): array
    {
        $access = [];
        foreach ($node->access ?? [] as $entry) {
            $op = trim((string) ($entry['op'] ?? ''));
            $privilege = trim((string) ($entry['privilege'] ?? ''));
            if ('' === $op) {
                continue;
            }
            if ('' === $privilege) {
                continue;
            }
            $access[] = ['op' => $op, 'privilege' => $privilege];
        }

        return $access;
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
