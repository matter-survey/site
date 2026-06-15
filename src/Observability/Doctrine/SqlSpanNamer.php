<?php

declare(strict_types=1);

namespace App\Observability\Doctrine;

/**
 * Derives a span name from a SQL statement following the pattern
 * `<OPERATION> <table>` (per OpenTelemetry database semantic conventions).
 * Falls back to just the operation when the table cannot be determined.
 */
final class SqlSpanNamer
{
    /**
     * @return non-empty-string
     */
    public static function nameFor(string $sql): string
    {
        $trimmed = ltrim($sql);
        if ('' === $trimmed) {
            return 'db';
        }

        if (1 === preg_match('/^(?<op>SELECT|INSERT|UPDATE|DELETE|REPLACE)\b/i', $trimmed, $opMatch)) {
            $op = strtoupper($opMatch['op']);
            $table = self::extractTable($op, $trimmed);

            return null === $table ? $op : $op.' '.$table;
        }

        if (1 === preg_match('/^(?<op>BEGIN|COMMIT|ROLLBACK|SAVEPOINT|RELEASE)\b/i', $trimmed, $txMatch)) {
            return strtoupper($txMatch['op']);
        }

        if (1 === preg_match('/^(?<op>CREATE|DROP|ALTER|TRUNCATE)\s+(?<obj>TABLE|INDEX|VIEW)/i', $trimmed, $ddlMatch)) {
            return strtoupper($ddlMatch['op'].' '.$ddlMatch['obj']);
        }

        $firstWord = strtoupper((string) (preg_split('/\s+/', $trimmed, 2)[0] ?? ''));

        return '' === $firstWord ? 'db' : $firstWord;
    }

    private static function extractTable(string $op, string $sql): ?string
    {
        $pattern = match ($op) {
            'SELECT', 'DELETE' => '/\bFROM\s+["`\'\[]?(?<table>[A-Za-z_][\w]*)["`\'\]]?/i',
            'INSERT', 'REPLACE' => '/\bINTO\s+["`\'\[]?(?<table>[A-Za-z_][\w]*)["`\'\]]?/i',
            'UPDATE' => '/^UPDATE\s+["`\'\[]?(?<table>[A-Za-z_][\w]*)["`\'\]]?/i',
            default => null,
        };

        if (null === $pattern) {
            return null;
        }

        return 1 === preg_match($pattern, $sql, $m) ? $m['table'] : null;
    }
}
