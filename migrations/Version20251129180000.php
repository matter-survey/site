<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add installation_products junction table for tracking which products are in each installation.
 * This enables "Frequently Paired With" recommendations.
 */
final class Version20251129180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add installation_products junction table for device pairing analytics';
    }

    public function up(Schema $schema): void
    {
        // Create junction table linking installations to products
        $this->addSql('
            CREATE TABLE installation_products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                installation_id TEXT NOT NULL,
                product_id INTEGER NOT NULL,
                first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                UNIQUE(installation_id, product_id)
            )
        ');

        // Indexes for efficient co-occurrence queries
        $this->addSql('CREATE INDEX idx_installation_products_installation ON installation_products(installation_id)');
        $this->addSql('CREATE INDEX idx_installation_products_product ON installation_products(product_id)');

        // Create a view for product co-occurrence counts (how often two products appear together)
        $this->addSql('
            CREATE VIEW product_cooccurrence AS
            SELECT
                ip1.product_id as product_a,
                ip2.product_id as product_b,
                COUNT(DISTINCT ip1.installation_id) as shared_installations
            FROM installation_products ip1
            JOIN installation_products ip2 ON ip1.installation_id = ip2.installation_id
            WHERE ip1.product_id < ip2.product_id
            GROUP BY ip1.product_id, ip2.product_id
            HAVING shared_installations >= 2
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS product_cooccurrence');
        $this->addSql('DROP TABLE IF EXISTS installation_products');
    }
}
