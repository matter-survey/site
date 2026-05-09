<?php

declare(strict_types=1);

namespace App\Observability\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware as DbalMiddleware;

final class TracingMiddleware implements DbalMiddleware
{
    public function wrap(Driver $driver): Driver
    {
        return new TracingDriver($driver);
    }
}
