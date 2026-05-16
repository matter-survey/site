<?php

declare(strict_types=1);

namespace App\Observability;

/**
 * Single source of truth for which attributes each domain span is allowed to
 * carry. Tests assert that recorded spans only contain attributes from this
 * list — a guardrail against accidental PII leaking into telemetry as the
 * codebase evolves.
 */
final class AttributeAllowlist
{
    /**
     * @return array<string, list<string>>
     */
    public static function map(): array
    {
        return [
            'telemetry.submit' => [
                'submission.schema_version',
                'submission.endpoint_count',
                'submission.device_count',
                'submission.devices_processed',
            ],
            'score.calculate' => [
                'score.endpoint_count',
                'score.value',
                'score.is_compliant',
            ],
            'dcl.sync' => [
                'dcl.vendor_count',
                'dcl.page_count',
            ],
            'dcl.fetch_page' => [
                'dcl.page',
                'dcl.page_size',
            ],
            'zap.sync' => [
                'zap.cluster_count',
            ],
            'matter_registry.lookup' => [
                'lookup.kind',
                'lookup.method',
                'cluster.hex_id',
                'device_type.hex_id',
                'lookup.cache_hit',
            ],
        ];
    }
}
