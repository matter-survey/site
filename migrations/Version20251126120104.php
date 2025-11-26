<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add attributes, commands, and features JSON columns to clusters table.
 * These columns store detailed cluster metadata from the Matter specification (ZAP XML files).
 */
final class Version20251126120104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add attributes, commands, and features JSON columns to clusters table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clusters ADD COLUMN attributes TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE clusters ADD COLUMN commands TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE clusters ADD COLUMN features TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite doesn't support DROP COLUMN directly in older versions
        // For SQLite, we'd need to recreate the table, but for simplicity:
        $this->addSql('ALTER TABLE clusters DROP COLUMN attributes');
        $this->addSql('ALTER TABLE clusters DROP COLUMN commands');
        $this->addSql('ALTER TABLE clusters DROP COLUMN features');
    }
}
