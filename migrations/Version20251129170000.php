<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add slug column to products table and update views.
 */
final class Version20251129170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add slug column to products table with backfill and update views';
    }

    public function up(Schema $schema): void
    {
        // Add slug column to products table
        $this->addSql('ALTER TABLE products ADD COLUMN slug VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX idx_products_slug ON products (slug)');

        // Backfill slugs for existing products
        // Format: slugified-product-name-vendorid-productid or product-vendorid-productid
        $this->addSql("
            UPDATE products SET slug = (
                CASE
                    WHEN product_name IS NOT NULL AND product_name != '' AND product_name != '-' THEN
                        LOWER(
                            REPLACE(
                                REPLACE(
                                    REPLACE(
                                        REPLACE(
                                            REPLACE(
                                                REPLACE(product_name, ' ', '-'),
                                                '/', '-'
                                            ),
                                            '&', '-'
                                        ),
                                        '.', ''
                                    ),
                                    ',', ''
                                ),
                                '--', '-'
                            )
                        ) || '-' || vendor_id || '-' || product_id
                    ELSE
                        'product-' || vendor_id || '-' || product_id
                END
            )
        ");

        // Drop and recreate the product_summary view to include slug and connectivity_types
        $this->addSql('DROP VIEW IF EXISTS device_summary');
        $this->addSql('DROP VIEW IF EXISTS product_summary');

        $this->addSql('
            CREATE VIEW product_summary AS
            SELECT
                p.id,
                p.slug,
                p.vendor_id,
                p.vendor_name,
                p.vendor_fk,
                v.slug as vendor_slug,
                p.product_id,
                p.product_name,
                p.product_url,
                p.support_url,
                p.user_manual_url,
                p.connectivity_types,
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

        // Recreate device_summary view (alias for product_summary)
        $this->addSql('CREATE VIEW device_summary AS SELECT * FROM product_summary');
    }

    public function down(Schema $schema): void
    {
        // Drop and recreate views without slug
        $this->addSql('DROP VIEW IF EXISTS device_summary');
        $this->addSql('DROP VIEW IF EXISTS product_summary');

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
                p.connectivity_types,
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

        $this->addSql('CREATE VIEW device_summary AS SELECT * FROM product_summary');

        // Drop slug column
        $this->addSql('DROP INDEX IF EXISTS idx_products_slug');
        // SQLite doesn't support DROP COLUMN directly, need to recreate table
        // For simplicity, we'll leave the column in place in down migration
    }
}
