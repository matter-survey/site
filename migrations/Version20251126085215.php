<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add company_legal_name and vendor_landing_page_url columns to vendors table.
 */
final class Version20251126085215 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add company_legal_name and vendor_landing_page_url to vendors table for DCL data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vendors ADD COLUMN company_legal_name VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE vendors ADD COLUMN vendor_landing_page_url VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vendors DROP COLUMN company_legal_name');
        $this->addSql('ALTER TABLE vendors DROP COLUMN vendor_landing_page_url');
    }
}
