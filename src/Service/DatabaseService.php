<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Database service for connection management.
 *
 * Schema is managed by Doctrine Migrations.
 */
class DatabaseService
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
