<?php

declare(strict_types=1);

namespace App\Observability\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware as DbalMiddleware;

/**
 * Applies SQLite connection pragmas (WAL journal, busy timeout) on every
 * connection. Auto-tagged as a doctrine.middleware via autoconfiguration.
 */
final class SqlitePragmaMiddleware implements DbalMiddleware
{
    public function wrap(Driver $driver): Driver
    {
        return new SqlitePragmaDriver($driver);
    }
}
