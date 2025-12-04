<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add columns for rich cluster data (schema v3) to product_endpoints.
 */
final class Version20251204210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add server_cluster_details, client_cluster_details, and schema_version columns to product_endpoints for v3 telemetry';
    }

    public function up(Schema $schema): void
    {
        // Add schema_version to track which telemetry schema was used
        $this->addSql('ALTER TABLE product_endpoints ADD COLUMN schema_version INTEGER DEFAULT 2');

        // Add detailed cluster info columns (v3 schema stores feature_map, attribute_list, command lists)
        $this->addSql('ALTER TABLE product_endpoints ADD COLUMN server_cluster_details JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE product_endpoints ADD COLUMN client_cluster_details JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite doesn't support DROP COLUMN directly, so we need to recreate the table
        // For simplicity in development, we'll just leave the columns
        // In production, a more complex migration would be needed
    }
}
