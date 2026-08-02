<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\Doctrine\SqlSpanNamer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlSpanNamerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function operationCases(): iterable
    {
        yield 'select' => ['SELECT * FROM products WHERE id = ?', 'SELECT'];
        yield 'insert' => ['INSERT INTO t (a) VALUES (1)', 'INSERT'];
        yield 'update' => ['UPDATE t SET a = 1', 'UPDATE'];
        yield 'delete' => ['DELETE FROM t', 'DELETE'];
        yield 'begin' => ['BEGIN', 'BEGIN'];
        yield 'commit' => ['COMMIT', 'COMMIT'];
        yield 'create table' => ['CREATE TABLE t (id INTEGER)', 'CREATE TABLE'];
        yield 'pragma' => ['PRAGMA journal_mode = WAL', 'PRAGMA'];
        yield 'leading whitespace + lowercase' => ['  select 1', 'SELECT'];
        yield 'empty' => ['', 'UNKNOWN'];
    }

    #[DataProvider('operationCases')]
    public function testOperationFor(string $sql, string $expected): void
    {
        $this->assertSame($expected, SqlSpanNamer::operationFor($sql));
    }
}
