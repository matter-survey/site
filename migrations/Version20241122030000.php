<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename devices table to products for consistency with Product entity.
 */
final class Version20241122030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename devices table to products, update related tables and views';
    }

    public function up(Schema $schema): void
    {
        // Drop views that reference devices table
        $this->addSql('DROP VIEW IF EXISTS cluster_stats');
        $this->addSql('DROP VIEW IF EXISTS device_summary');

        // Rename devices table to products
        $this->addSql('ALTER TABLE devices RENAME TO products');

        // Rename device_versions to product_versions
        $this->addSql('ALTER TABLE device_versions RENAME TO product_versions');

        // Rename device_endpoints to product_endpoints
        $this->addSql('ALTER TABLE device_endpoints RENAME TO product_endpoints');

        // Recreate indexes with new names (SQLite doesn't support renaming indexes)
        $this->addSql('DROP INDEX IF EXISTS idx_devices_vendor');
        $this->addSql('DROP INDEX IF EXISTS idx_devices_product');
        $this->addSql('DROP INDEX IF EXISTS idx_devices_vendor_fk');
        $this->addSql('CREATE INDEX idx_products_vendor ON products(vendor_id)');
        $this->addSql('CREATE INDEX idx_products_product ON products(product_id)');
        $this->addSql('CREATE INDEX idx_products_vendor_fk ON products(vendor_fk)');

        $this->addSql('DROP INDEX IF EXISTS idx_device_versions_device');
        $this->addSql('CREATE INDEX idx_product_versions_product ON product_versions(device_id)');

        $this->addSql('DROP INDEX IF EXISTS idx_device_endpoints_device');
        $this->addSql('CREATE INDEX idx_product_endpoints_product ON product_endpoints(device_id)');

        // Recreate views with new table names
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
                    SELECT 1 FROM json_each(pe.clusters) WHERE value = 30
                ) THEN 1 ELSE 0 END) as supports_binding
            FROM products p
            LEFT JOIN vendors v ON p.vendor_fk = v.id
            LEFT JOIN product_endpoints pe ON p.id = pe.device_id
            GROUP BY p.id
        ');

        $this->addSql('
            CREATE VIEW cluster_stats AS
            SELECT
                json_each.value as cluster_id,
                COUNT(DISTINCT pe.device_id) as product_count
            FROM product_endpoints pe, json_each(pe.clusters)
            GROUP BY json_each.value
            ORDER BY product_count DESC
        ');

        // Keep device_summary as alias for backwards compatibility
        $this->addSql('
            CREATE VIEW device_summary AS
            SELECT * FROM product_summary
        ');
    }

    public function down(Schema $schema): void
    {
        // Drop views
        $this->addSql('DROP VIEW IF EXISTS device_summary');
        $this->addSql('DROP VIEW IF EXISTS cluster_stats');
        $this->addSql('DROP VIEW IF EXISTS product_summary');

        // Rename tables back
        $this->addSql('ALTER TABLE products RENAME TO devices');
        $this->addSql('ALTER TABLE product_versions RENAME TO device_versions');
        $this->addSql('ALTER TABLE product_endpoints RENAME TO device_endpoints');

        // Recreate indexes
        $this->addSql('DROP INDEX IF EXISTS idx_products_vendor');
        $this->addSql('DROP INDEX IF EXISTS idx_products_product');
        $this->addSql('DROP INDEX IF EXISTS idx_products_vendor_fk');
        $this->addSql('CREATE INDEX idx_devices_vendor ON devices(vendor_id)');
        $this->addSql('CREATE INDEX idx_devices_product ON devices(product_id)');
        $this->addSql('CREATE INDEX idx_devices_vendor_fk ON devices(vendor_fk)');

        $this->addSql('DROP INDEX IF EXISTS idx_product_versions_product');
        $this->addSql('CREATE INDEX idx_device_versions_device ON device_versions(device_id)');

        $this->addSql('DROP INDEX IF EXISTS idx_product_endpoints_product');
        $this->addSql('CREATE INDEX idx_device_endpoints_device ON device_endpoints(device_id)');

        // Recreate original views
        $this->addSql('
            CREATE VIEW device_summary AS
            SELECT
                d.id,
                d.vendor_id,
                d.vendor_name,
                d.vendor_fk,
                v.slug as vendor_slug,
                d.product_id,
                d.product_name,
                d.submission_count,
                d.first_seen,
                d.last_seen,
                COUNT(DISTINCT de.endpoint_id) as endpoint_count,
                MAX(CASE WHEN EXISTS (
                    SELECT 1 FROM json_each(de.clusters) WHERE value = 30
                ) THEN 1 ELSE 0 END) as supports_binding
            FROM devices d
            LEFT JOIN vendors v ON d.vendor_fk = v.id
            LEFT JOIN device_endpoints de ON d.id = de.device_id
            GROUP BY d.id
        ');

        $this->addSql('
            CREATE VIEW cluster_stats AS
            SELECT
                json_each.value as cluster_id,
                COUNT(DISTINCT de.device_id) as device_count
            FROM device_endpoints de, json_each(de.clusters)
            GROUP BY json_each.value
            ORDER BY device_count DESC
        ');
    }
}
