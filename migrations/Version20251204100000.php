<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add scoring_weights to device_types and create device_scores cache table.
 */
final class Version20251204100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add scoring_weights column to device_types and create device_scores cache table for star ratings';
    }

    public function up(Schema $schema): void
    {
        // Add scoring_weights column to device_types table
        $this->addSql('ALTER TABLE device_types ADD COLUMN scoring_weights JSON DEFAULT NULL');

        // Create device_scores cache table for efficient list page queries
        $this->addSql('
            CREATE TABLE device_scores (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id INTEGER NOT NULL UNIQUE,
                overall_score REAL NOT NULL,
                star_rating INTEGER NOT NULL,
                is_compliant INTEGER NOT NULL DEFAULT 1,
                scores_by_type JSON NOT NULL,
                best_version TEXT DEFAULT NULL,
                computed_at DATETIME NOT NULL,
                FOREIGN KEY (device_id) REFERENCES products(id) ON DELETE CASCADE
            )
        ');

        // Add indexes for efficient querying
        $this->addSql('CREATE INDEX idx_device_scores_rating ON device_scores (star_rating)');
        $this->addSql('CREATE INDEX idx_device_scores_score ON device_scores (overall_score)');
        $this->addSql('CREATE INDEX idx_device_scores_compliant ON device_scores (is_compliant)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS device_scores');
        $this->addSql('ALTER TABLE device_types DROP COLUMN scoring_weights');
    }
}
