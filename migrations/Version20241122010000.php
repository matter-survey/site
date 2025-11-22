<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add vendors table and link devices to vendors.
 */
final class Version20241122010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add vendors table with slug and spec_id, link devices to vendors';
    }

    public function up(Schema $schema): void
    {
        // Create vendors table
        $this->addSql('
            CREATE TABLE vendors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug VARCHAR(255) NOT NULL UNIQUE,
                spec_id INTEGER UNIQUE,
                name VARCHAR(255) NOT NULL,
                device_count INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // Index for faster lookups
        $this->addSql('CREATE INDEX idx_vendors_spec_id ON vendors(spec_id)');

        // Add vendor foreign key column to devices
        $this->addSql('ALTER TABLE devices ADD COLUMN vendor_fk INTEGER REFERENCES vendors(id)');
        $this->addSql('CREATE INDEX idx_devices_vendor_fk ON devices(vendor_fk)');

        // Populate vendors from existing device data
        // Generate slugs by lowercasing and replacing spaces/special chars with hyphens
        $this->addSql("
            INSERT INTO vendors (slug, spec_id, name, device_count, created_at, updated_at)
            SELECT
                lower(replace(replace(replace(replace(COALESCE(vendor_name, 'vendor-' || vendor_id), ' ', '-'), '.', ''), ',', ''), '/', '-')),
                vendor_id,
                COALESCE(vendor_name, 'Vendor ' || vendor_id),
                COUNT(*),
                MIN(first_seen),
                MAX(last_seen)
            FROM devices
            WHERE vendor_id IS NOT NULL
            GROUP BY vendor_id
        ");

        // Link existing devices to their vendors
        $this->addSql('
            UPDATE devices
            SET vendor_fk = (
                SELECT v.id FROM vendors v WHERE v.spec_id = devices.vendor_id
            )
            WHERE vendor_id IS NOT NULL
        ');

        // Update device_summary view to include vendor slug
        $this->addSql('DROP VIEW IF EXISTS device_summary');
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
                MAX(de.has_binding_cluster) as supports_binding
            FROM devices d
            LEFT JOIN vendors v ON d.vendor_fk = v.id
            LEFT JOIN device_endpoints de ON d.id = de.device_id
            GROUP BY d.id
        ');
    }

    public function down(Schema $schema): void
    {
        // Restore original device_summary view
        $this->addSql('DROP VIEW IF EXISTS device_summary');
        $this->addSql('
            CREATE VIEW device_summary AS
            SELECT
                d.id,
                d.vendor_id,
                d.vendor_name,
                d.product_id,
                d.product_name,
                d.submission_count,
                d.first_seen,
                d.last_seen,
                COUNT(DISTINCT de.endpoint_id) as endpoint_count,
                MAX(de.has_binding_cluster) as supports_binding
            FROM devices d
            LEFT JOIN device_endpoints de ON d.id = de.device_id
            GROUP BY d.id
        ');

        // Remove vendor_fk from devices (SQLite limitation: can't drop columns easily)
        // We'll recreate the table without the column
        $this->addSql('DROP INDEX IF EXISTS idx_devices_vendor_fk');

        // For SQLite, we need to recreate the table to remove a column
        $this->addSql('
            CREATE TABLE devices_backup (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                vendor_id INTEGER,
                vendor_name TEXT,
                product_id INTEGER,
                product_name TEXT,
                first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
                submission_count INTEGER DEFAULT 1,
                UNIQUE(vendor_id, product_id)
            )
        ');
        $this->addSql('INSERT INTO devices_backup SELECT id, vendor_id, vendor_name, product_id, product_name, first_seen, last_seen, submission_count FROM devices');
        $this->addSql('DROP TABLE devices');
        $this->addSql('ALTER TABLE devices_backup RENAME TO devices');
        $this->addSql('CREATE INDEX idx_devices_vendor ON devices(vendor_id)');
        $this->addSql('CREATE INDEX idx_devices_product ON devices(product_id)');

        // Drop vendors table
        $this->addSql('DROP INDEX IF EXISTS idx_vendors_spec_id');
        $this->addSql('DROP TABLE IF EXISTS vendors');
    }
}
