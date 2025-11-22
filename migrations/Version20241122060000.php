<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create device_types table for Matter device type metadata.
 */
final class Version20241122060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create device_types table for Matter device type metadata from spec';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE device_types (
                id INTEGER PRIMARY KEY,
                hex_id VARCHAR(10) NOT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                spec_version VARCHAR(10),
                category VARCHAR(50),
                display_category VARCHAR(50),
                device_class VARCHAR(50),
                scope VARCHAR(50),
                superset VARCHAR(255),
                icon VARCHAR(50),
                mandatory_server_clusters JSON NOT NULL DEFAULT "[]",
                optional_server_clusters JSON NOT NULL DEFAULT "[]",
                mandatory_client_clusters JSON NOT NULL DEFAULT "[]",
                optional_client_clusters JSON NOT NULL DEFAULT "[]",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->addSql('CREATE INDEX idx_device_types_category ON device_types(display_category)');
        $this->addSql('CREATE INDEX idx_device_types_spec_version ON device_types(spec_version)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS device_types');
    }
}
