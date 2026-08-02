<?php

declare(strict_types=1);

namespace App\Observability\Doctrine;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Sets busy_timeout on every fresh SQLite connection: a writer waits up to 5s
 * for the write lock instead of failing immediately (SQLite's default is 0),
 * which smooths concurrent /api/submit writes against page reads.
 *
 * Only per-connection pragmas that SQLite permits inside an open transaction
 * belong here — the test suite (DAMA) holds one open for the whole test, and
 * SQLite rejects changing journal_mode or synchronous while a transaction is
 * active. WAL journal mode is therefore a persistent, database-level setting
 * enabled once via a migration ({@see \DoctrineMigrations}), and synchronous is
 * left at WAL's default.
 *
 * The app is SQLite-only, so the pragma is applied unconditionally.
 */
final class SqlitePragmaDriver extends AbstractDriverMiddleware
{
    #[\Override]
    public function connect(
        #[\SensitiveParameter]
        array $params,
    ): DriverConnection {
        $connection = parent::connect($params);

        $connection->exec('PRAGMA busy_timeout = 5000');

        return $connection;
    }
}
