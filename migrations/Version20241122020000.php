<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove has_binding_cluster column and derive binding support from clusters JSON.
 */
final class Version20241122020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove has_binding_cluster column, derive binding support from clusters array containing cluster 30 (0x001E)';
    }

    public function up(Schema $schema): void
    {
        // Drop views that reference device_endpoints before modifying the table
        $this->addSql('DROP VIEW IF EXISTS cluster_stats');
        $this->addSql('DROP VIEW IF EXISTS device_summary');

        // SQLite requires table recreation to drop a column
        // First, create a backup table without the has_binding_cluster column
        $this->addSql('
            CREATE TABLE device_endpoints_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id INTEGER NOT NULL,
                endpoint_id INTEGER NOT NULL,
                device_types JSON NOT NULL,
                clusters JSON NOT NULL,
                FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
                UNIQUE(device_id, endpoint_id)
            )
        ');

        // Copy data (excluding has_binding_cluster)
        $this->addSql('
            INSERT INTO device_endpoints_new (id, device_id, endpoint_id, device_types, clusters)
            SELECT id, device_id, endpoint_id, device_types, clusters
            FROM device_endpoints
        ');

        // Drop old table and rename new one
        $this->addSql('DROP TABLE device_endpoints');
        $this->addSql('ALTER TABLE device_endpoints_new RENAME TO device_endpoints');

        // Recreate index
        $this->addSql('CREATE INDEX idx_device_endpoints_device ON device_endpoints(device_id)');

        // Recreate device_summary view to derive supports_binding from clusters JSON
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

        // Recreate cluster_stats view
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
        // Recreate table with has_binding_cluster column
        $this->addSql('
            CREATE TABLE device_endpoints_new (
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

        // Copy data and derive has_binding_cluster from clusters
        $this->addSql('
            INSERT INTO device_endpoints_new (id, device_id, endpoint_id, device_types, clusters, has_binding_cluster)
            SELECT
                id,
                device_id,
                endpoint_id,
                device_types,
                clusters,
                CASE WHEN EXISTS (SELECT 1 FROM json_each(clusters) WHERE value = 30) THEN 1 ELSE 0 END
            FROM device_endpoints
        ');

        // Drop old table and rename new one
        $this->addSql('DROP TABLE device_endpoints');
        $this->addSql('ALTER TABLE device_endpoints_new RENAME TO device_endpoints');

        // Recreate index
        $this->addSql('CREATE INDEX idx_device_endpoints_device ON device_endpoints(device_id)');

        // Restore device_summary view using has_binding_cluster
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
}
