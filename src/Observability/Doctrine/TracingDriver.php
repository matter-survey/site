<?php

declare(strict_types=1);

namespace App\Observability\Doctrine;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class TracingDriver extends AbstractDriverMiddleware
{
    public function connect(
        #[\SensitiveParameter]
        array $params,
    ): DriverConnection {
        return new TracingConnection(parent::connect($params));
    }
}
