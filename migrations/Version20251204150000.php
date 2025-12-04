<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Update product_summary view to use brand name from vendors table instead of legal name.
 */
final class Version20251204150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Use vendor brand name instead of legal name in product_summary view';
    }

    public function up(Schema $schema): void
    {
        // Drop existing views (order matters due to dependencies)
        $this->addSql('DROP VIEW IF EXISTS device_summary');
        $this->addSql('DROP VIEW IF EXISTS product_summary');

        // Recreate product_summary with COALESCE to prefer vendor brand name
        $this->addSql('
            CREATE VIEW product_summary AS
            SELECT
                p.id,
                p.slug,
                p.vendor_id,
                COALESCE(v.name, p.vendor_name) as vendor_name,
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

        // Recreate device_summary as alias to product_summary
        $this->addSql('CREATE VIEW device_summary AS SELECT * FROM product_summary');
    }

    public function down(Schema $schema): void
    {
        // Restore original views
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

        $this->addSql('CREATE VIEW device_summary AS SELECT * FROM product_summary');
    }
}
