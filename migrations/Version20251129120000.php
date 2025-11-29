<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add connectivity_types column to products table.
 * Stores network transport types (thread, wifi, ethernet) derived from telemetry.
 * Backfills existing data from product_endpoints server_clusters.
 */
final class Version20251129120000 extends AbstractMigration
{
    /**
     * Network Diagnostics cluster IDs.
     */
    private const THREAD_CLUSTER = 53;
    private const WIFI_CLUSTER = 54;
    private const ETHERNET_CLUSTER = 55;

    public function getDescription(): string
    {
        return 'Add connectivity_types JSON column to products table and backfill from endpoint data';
    }

    public function up(Schema $schema): void
    {
        // Add the column
        $this->addSql('ALTER TABLE products ADD COLUMN connectivity_types TEXT DEFAULT NULL');

        // Backfill connectivity types from existing endpoint data
        // This query finds products that have network diagnostic clusters in their endpoints
        $this->addSql('
            UPDATE products SET connectivity_types = (
                SELECT json_group_array(DISTINCT connectivity_type)
                FROM (
                    SELECT CASE
                        WHEN EXISTS (
                            SELECT 1 FROM product_endpoints pe, json_each(pe.server_clusters) jc
                            WHERE pe.device_id = products.id AND jc.value = ' . self::THREAD_CLUSTER . '
                        ) THEN "thread"
                    END as connectivity_type
                    UNION ALL
                    SELECT CASE
                        WHEN EXISTS (
                            SELECT 1 FROM product_endpoints pe, json_each(pe.server_clusters) jc
                            WHERE pe.device_id = products.id AND jc.value = ' . self::WIFI_CLUSTER . '
                        ) THEN "wifi"
                    END
                    UNION ALL
                    SELECT CASE
                        WHEN EXISTS (
                            SELECT 1 FROM product_endpoints pe, json_each(pe.server_clusters) jc
                            WHERE pe.device_id = products.id AND jc.value = ' . self::ETHERNET_CLUSTER . '
                        ) THEN "ethernet"
                    END
                ) WHERE connectivity_type IS NOT NULL
            )
            WHERE EXISTS (
                SELECT 1 FROM product_endpoints pe, json_each(pe.server_clusters) jc
                WHERE pe.device_id = products.id
                  AND jc.value IN (' . self::THREAD_CLUSTER . ', ' . self::WIFI_CLUSTER . ', ' . self::ETHERNET_CLUSTER . ')
            )
        ');

        // Clean up any empty arrays (set to NULL)
        $this->addSql('UPDATE products SET connectivity_types = NULL WHERE connectivity_types = "[]"');

        // Update the views to include connectivity_types
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

        $this->addSql('
            CREATE VIEW device_summary AS
            SELECT * FROM product_summary
        ');
    }

    public function down(Schema $schema): void
    {
        // Restore views without connectivity_types
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

        $this->addSql('ALTER TABLE products DROP COLUMN connectivity_types');
    }
}
