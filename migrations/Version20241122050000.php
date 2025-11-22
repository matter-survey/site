<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add version tracking to product_endpoints for per-version cluster configurations.
 */
final class Version20241122050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add hardware/software version columns to product_endpoints for version-specific endpoint tracking';
    }

    public function up(Schema $schema): void
    {
        // Drop views that reference product_endpoints
        $this->addSql('DROP VIEW IF EXISTS cluster_stats');
        $this->addSql('DROP VIEW IF EXISTS device_summary');
        $this->addSql('DROP VIEW IF EXISTS product_summary');

        // Drop and recreate product_endpoints with version columns
        $this->addSql('DROP TABLE IF EXISTS product_endpoints');

        $this->addSql('
            CREATE TABLE product_endpoints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id INTEGER NOT NULL,
                endpoint_id INTEGER NOT NULL,
                hardware_version VARCHAR(50),
                software_version VARCHAR(50),
                device_types JSON NOT NULL,
                server_clusters JSON NOT NULL DEFAULT "[]",
                client_clusters JSON NOT NULL DEFAULT "[]",
                first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
                submission_count INTEGER DEFAULT 1,
                FOREIGN KEY (device_id) REFERENCES products(id) ON DELETE CASCADE,
                UNIQUE(device_id, endpoint_id, hardware_version, software_version)
            )
        ');

        // Recreate index
        $this->addSql('CREATE INDEX idx_product_endpoints_product ON product_endpoints(device_id)');
        $this->addSql('CREATE INDEX idx_product_endpoints_version ON product_endpoints(device_id, hardware_version, software_version)');

        // Recreate product_summary view - derive binding support from any version's endpoints
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

        // Recreate cluster_stats view with cluster type distinction
        $this->addSql('
            CREATE VIEW cluster_stats AS
            SELECT
                json_each.value as cluster_id,
                "server" as cluster_type,
                COUNT(DISTINCT pe.device_id) as product_count
            FROM product_endpoints pe, json_each(pe.server_clusters)
            GROUP BY json_each.value
            UNION ALL
            SELECT
                json_each.value as cluster_id,
                "client" as cluster_type,
                COUNT(DISTINCT pe.device_id) as product_count
            FROM product_endpoints pe, json_each(pe.client_clusters)
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

        // Recreate table without version columns
        $this->addSql('DROP TABLE IF EXISTS product_endpoints');

        $this->addSql('
            CREATE TABLE product_endpoints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id INTEGER NOT NULL,
                endpoint_id INTEGER NOT NULL,
                device_types JSON NOT NULL,
                server_clusters JSON NOT NULL DEFAULT "[]",
                client_clusters JSON NOT NULL DEFAULT "[]",
                FOREIGN KEY (device_id) REFERENCES products(id) ON DELETE CASCADE,
                UNIQUE(device_id, endpoint_id)
            )
        ');

        $this->addSql('CREATE INDEX idx_product_endpoints_product ON product_endpoints(device_id)');

        // Recreate original views
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
            CREATE VIEW cluster_stats AS
            SELECT
                json_each.value as cluster_id,
                "server" as cluster_type,
                COUNT(DISTINCT pe.device_id) as product_count
            FROM product_endpoints pe, json_each(pe.server_clusters)
            GROUP BY json_each.value
            UNION ALL
            SELECT
                json_each.value as cluster_id,
                "client" as cluster_type,
                COUNT(DISTINCT pe.device_id) as product_count
            FROM product_endpoints pe, json_each(pe.client_clusters)
            GROUP BY json_each.value
            ORDER BY product_count DESC
        ');

        $this->addSql('
            CREATE VIEW device_summary AS
            SELECT * FROM product_summary
        ');
    }
}
