<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add product URL fields and device type ID to products table.
 * Update product_summary view to include new columns.
 */
final class Version20251126100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add device_type_id, part_number, product_url, support_url, user_manual_url to products table and update views';
    }

    public function up(Schema $schema): void
    {
        // Add new columns to products table
        $this->addSql('ALTER TABLE products ADD COLUMN device_type_id INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN part_number VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN product_url VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN support_url VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN user_manual_url VARCHAR(512) DEFAULT NULL');

        // Drop existing views
        $this->addSql('DROP VIEW IF EXISTS device_summary');
        $this->addSql('DROP VIEW IF EXISTS product_summary');

        // Recreate product_summary view with new columns
        $this->addSql('
            CREATE VIEW product_summary AS
            SELECT
                p.id,
                p.vendor_id,
                p.vendor_name,
                p.vendor_fk,
                v.slug as vendor_slug,
                p.product_id,
                p.product_name,
                p.product_url,
                p.support_url,
                p.user_manual_url,
                p.submission_count,
                p.first_seen,
                p.last_seen,
                COUNT(DISTINCT pe.endpoint_id) as endpoint_count,
                MAX(CASE WHEN EXISTS (
                    SELECT 1 FROM json_each(pe.server_clusters) WHERE value = 30
                ) OR EXISTS (
                    SELECT 1 FROM json_each(pe.client_clusters) WHERE value = 30
                ) THEN 1 ELSE 0 END) as supports_binding
            FROM products p
            LEFT JOIN vendors v ON p.vendor_fk = v.id
            LEFT JOIN product_endpoints pe ON p.id = pe.device_id
            GROUP BY p.id
        ');

        // Recreate device_summary as alias
        $this->addSql('
            CREATE VIEW device_summary AS
            SELECT * FROM product_summary
        ');
    }

    public function down(Schema $schema): void
    {
        // Drop views
        $this->addSql('DROP VIEW IF EXISTS device_summary');
        $this->addSql('DROP VIEW IF EXISTS product_summary');

        // Recreate original product_summary view without new columns
        $this->addSql('
            CREATE VIEW product_summary AS
            SELECT
                p.id,
                p.vendor_id,
                p.vendor_name,
                p.vendor_fk,
                v.slug as vendor_slug,
                p.product_id,
                p.product_name,
                p.submission_count,
                p.first_seen,
                p.last_seen,
                COUNT(DISTINCT pe.endpoint_id) as endpoint_count,
                MAX(CASE WHEN EXISTS (
                    SELECT 1 FROM json_each(pe.server_clusters) WHERE value = 30
                ) OR EXISTS (
                    SELECT 1 FROM json_each(pe.client_clusters) WHERE value = 30
                ) THEN 1 ELSE 0 END) as supports_binding
            FROM products p
            LEFT JOIN vendors v ON p.vendor_fk = v.id
            LEFT JOIN product_endpoints pe ON p.id = pe.device_id
            GROUP BY p.id
        ');

        $this->addSql('
            CREATE VIEW device_summary AS
            SELECT * FROM product_summary
        ');

        // SQLite doesn't support DROP COLUMN, so we cannot cleanly rollback the column additions
        // In a real production environment, you'd need to recreate the table without these columns
    }
}
