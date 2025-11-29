<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add certification date and certificate ID fields to products table.
 */
final class Version20251130100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add certification_date, certificate_id, and software_version_string columns to products table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products ADD COLUMN certification_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN certificate_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN software_version_string VARCHAR(64) DEFAULT NULL');

        // Add index on certification_date for efficient timeline queries
        $this->addSql('CREATE INDEX idx_products_certification_date ON products (certification_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_products_certification_date');
        $this->addSql('ALTER TABLE products DROP COLUMN certification_date');
        $this->addSql('ALTER TABLE products DROP COLUMN certificate_id');
        $this->addSql('ALTER TABLE products DROP COLUMN software_version_string');
    }
}
