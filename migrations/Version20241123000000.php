<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create clusters table for Matter cluster metadata.
 */
final class Version20241123000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create clusters table for Matter cluster metadata from spec';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE clusters (
                id INTEGER PRIMARY KEY,
                hex_id VARCHAR(10) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                spec_version VARCHAR(10),
                category VARCHAR(50),
                is_global INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->addSql('CREATE INDEX idx_clusters_hex_id ON clusters(hex_id)');
        $this->addSql('CREATE INDEX idx_clusters_category ON clusters(category)');
        $this->addSql('CREATE INDEX idx_clusters_spec_version ON clusters(spec_version)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS clusters');
    }
}
