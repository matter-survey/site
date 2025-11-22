<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Database service for SQLite connection management and schema initialization.
 */
class DatabaseService
{
    private bool $initialized = false;

    public function __construct(
        private Connection $connection,
        private string $schemaPath,
    ) {}

    public function getConnection(): Connection
    {
        if (!$this->initialized) {
            $this->ensureSchemaInitialized();
            $this->initialized = true;
        }

        return $this->connection;
    }

    private function ensureSchemaInitialized(): void
    {
        // Check if database has tables (schema already initialized)
        $tables = $this->connection->executeQuery(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='devices'"
        )->fetchOne();

        if ($tables !== false) {
            // Schema already exists
            return;
        }

        // Initialize schema for new database
        $this->initializeSchema();
    }

    private function initializeSchema(): void
    {
        if (!file_exists($this->schemaPath)) {
            throw new RuntimeException("Schema file not found: {$this->schemaPath}");
        }

        // Ensure the data directory exists
        $dbPath = $this->connection->getParams()['path'] ?? null;
        if ($dbPath) {
            $dbDir = dirname($dbPath);
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0755, true);
            }
        }

        $schema = file_get_contents($this->schemaPath);
        $this->connection->executeStatement($schema);
    }
}
