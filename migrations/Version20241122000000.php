<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema migration - creates all tables, indexes, and views.
 */
final class Version20241122000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: devices, versions, endpoints, installations, submissions tables with views';
    }

    public function up(Schema $schema): void
    {
        // Devices table: stores unique device products
        $this->addSql('
            CREATE TABLE devices (
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

        // Device versions: tracks hardware/software versions seen for each device
        $this->addSql('
            CREATE TABLE device_versions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id INTEGER NOT NULL,
                hardware_version TEXT,
                software_version TEXT,
                first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
                count INTEGER DEFAULT 1,
                FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
                UNIQUE(device_id, hardware_version, software_version)
            )
        ');

        // Device endpoints: capability structure per endpoint
        $this->addSql('
            CREATE TABLE device_endpoints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id INTEGER NOT NULL,
                endpoint_id INTEGER NOT NULL,
                device_types JSON NOT NULL,
                clusters JSON NOT NULL,
                has_binding_cluster BOOLEAN DEFAULT FALSE,
                FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
                UNIQUE(device_id, endpoint_id)
            )
        ');

        // Installations: track unique installations for deduplication
        $this->addSql('
            CREATE TABLE installations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                installation_id TEXT UNIQUE NOT NULL,
                first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
                submission_count INTEGER DEFAULT 1
            )
        ');

        // Submissions log: audit trail of submissions
        $this->addSql('
            CREATE TABLE submissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                installation_id TEXT NOT NULL,
                device_count INTEGER NOT NULL,
                submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                ip_hash TEXT
            )
        ');

        // Indexes for performance
        $this->addSql('CREATE INDEX idx_devices_vendor ON devices(vendor_id)');
        $this->addSql('CREATE INDEX idx_devices_product ON devices(product_id)');
        $this->addSql('CREATE INDEX idx_device_endpoints_device ON device_endpoints(device_id)');
        $this->addSql('CREATE INDEX idx_device_versions_device ON device_versions(device_id)');
        $this->addSql('CREATE INDEX idx_submissions_installation ON submissions(installation_id)');

        // Views for common queries
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

    public function down(Schema $schema): void
    {
        // Drop views first
        $this->addSql('DROP VIEW IF EXISTS cluster_stats');
        $this->addSql('DROP VIEW IF EXISTS device_summary');

        // Drop indexes
        $this->addSql('DROP INDEX IF EXISTS idx_submissions_installation');
        $this->addSql('DROP INDEX IF EXISTS idx_device_versions_device');
        $this->addSql('DROP INDEX IF EXISTS idx_device_endpoints_device');
        $this->addSql('DROP INDEX IF EXISTS idx_devices_product');
        $this->addSql('DROP INDEX IF EXISTS idx_devices_vendor');

        // Drop tables in reverse order of creation (respecting foreign keys)
        $this->addSql('DROP TABLE IF EXISTS submissions');
        $this->addSql('DROP TABLE IF EXISTS installations');
        $this->addSql('DROP TABLE IF EXISTS device_endpoints');
        $this->addSql('DROP TABLE IF EXISTS device_versions');
        $this->addSql('DROP TABLE IF EXISTS devices');
    }
}
