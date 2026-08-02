<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Switch the SQLite database to WAL journal mode.
 *
 * WAL lets readers and the writer proceed concurrently instead of serialising
 * on the single rollback-journal lock, which is what let a /api/submit write
 * (or a long device page read) stall other requests for tens of seconds. It is
 * a persistent, database-level setting stored in the file header, so it is set
 * once here rather than on every connection (SQLite forbids changing
 * journal_mode inside a transaction). The per-connection busy_timeout and
 * synchronous pragmas live in {@see \App\Observability\Doctrine\SqlitePragmaDriver}.
 *
 * Marked non-transactional because the pragma cannot run inside the transaction
 * the migration runner would otherwise open.
 */
final class Version20260802120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable SQLite WAL journal mode';
    }

    #[\Override]
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform,
            'WAL journal mode only applies to SQLite',
        );

        $this->connection->executeStatement('PRAGMA journal_mode = WAL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform,
            'WAL journal mode only applies to SQLite',
        );

        $this->connection->executeStatement('PRAGMA journal_mode = DELETE');
    }
}
