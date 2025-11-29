<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add additional DCL fields to products table:
 * - ICD (Intermittently Connected Device) fields
 * - Secondary commissioning steps
 * - Label/Setup File (LSF) fields
 * - Certified software versions from DCL compliance data
 */
final class Version20251129190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add additional DCL fields to products (ICD, secondary commissioning, LSF, certified versions)';
    }

    public function up(Schema $schema): void
    {
        // Add ICD (Intermittently Connected Device) fields
        $this->addSql('ALTER TABLE products ADD COLUMN icd_user_active_mode_trigger_hint INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN icd_user_active_mode_trigger_instruction TEXT DEFAULT NULL');

        // Add secondary commissioning steps fields
        $this->addSql('ALTER TABLE products ADD COLUMN commissioning_secondary_steps_hint INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN commissioning_secondary_steps_instruction TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN commissioning_fallback_url VARCHAR(512) DEFAULT NULL');

        // Add LSF (Label/Setup File) fields
        $this->addSql('ALTER TABLE products ADD COLUMN lsf_url VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN lsf_revision INTEGER DEFAULT NULL');

        // Add certified software versions (JSON stored as TEXT in SQLite)
        $this->addSql('ALTER TABLE products ADD COLUMN certified_software_versions TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite doesn't support DROP COLUMN directly in older versions
        // For SQLite, we'd need to recreate the table
        // This is a simplified down migration that works with newer SQLite
        $this->addSql('ALTER TABLE products DROP COLUMN icd_user_active_mode_trigger_hint');
        $this->addSql('ALTER TABLE products DROP COLUMN icd_user_active_mode_trigger_instruction');
        $this->addSql('ALTER TABLE products DROP COLUMN commissioning_secondary_steps_hint');
        $this->addSql('ALTER TABLE products DROP COLUMN commissioning_secondary_steps_instruction');
        $this->addSql('ALTER TABLE products DROP COLUMN commissioning_fallback_url');
        $this->addSql('ALTER TABLE products DROP COLUMN lsf_url');
        $this->addSql('ALTER TABLE products DROP COLUMN lsf_revision');
        $this->addSql('ALTER TABLE products DROP COLUMN certified_software_versions');
    }
}
