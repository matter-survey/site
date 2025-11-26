<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add DCL product fields: discovery capabilities, commissioning, maintenance, and factory reset.
 */
final class Version20251126114846 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add DCL product fields: discovery capabilities, commissioning, maintenance, and factory reset';
    }

    public function up(Schema $schema): void
    {
        // Add new columns to products table for DCL data
        $this->addSql('ALTER TABLE products ADD COLUMN discovery_capabilities_bitmask INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN commissioning_custom_flow INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN commissioning_custom_flow_url VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN commissioning_initial_steps_hint INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN commissioning_initial_steps_instruction CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN maintenance_url VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN factory_reset_steps_hint INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN factory_reset_steps_instruction CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite doesn't support DROP COLUMN directly, would need to recreate table
        // For simplicity, we just document that these columns would be removed
        $this->throwIrreversibleMigrationException('Cannot remove columns in SQLite without recreating table');
    }
}
