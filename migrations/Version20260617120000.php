<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add supports_groups and supports_scenes columns to the product_summary view.
 *
 * Extends the coordination-feature recognition (previously binding-only) so the
 * device list, facets, and stats pages can read Groups (0x0004) and Scenes
 * (0x0062 Scenes Management or deprecated 0x0005) support without recomputing
 * from raw cluster JSON.
 */
final class Version20260617120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add supports_groups and supports_scenes columns to product_summary view';
    }

    public function up(Schema $schema): void
    {
        // Drop existing views (order matters due to dependencies)
        $this->addSql('DROP VIEW IF EXISTS device_summary');
        $this->addSql('DROP VIEW IF EXISTS product_summary');

        // Recreate product_summary with binding + groups + scenes support flags
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
                ) THEN 1 ELSE 0 END) as supports_binding,
                MAX(CASE WHEN EXISTS (
                    SELECT 1 FROM json_each(pe.server_clusters) WHERE value = 4
                ) THEN 1 ELSE 0 END) as supports_groups,
                MAX(CASE WHEN EXISTS (
                    SELECT 1 FROM json_each(pe.server_clusters) WHERE value IN (98, 5)
                ) THEN 1 ELSE 0 END) as supports_scenes
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
        // Restore the binding-only view definition
        $this->addSql('DROP VIEW IF EXISTS device_summary');
        $this->addSql('DROP VIEW IF EXISTS product_summary');

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

        $this->addSql('CREATE VIEW device_summary AS SELECT * FROM product_summary');
    }
}
