<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create wizard_analytics table for tracking user wizard interactions.
 */
final class Version20251204200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create wizard_analytics table for tracking device finder wizard usage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE wizard_analytics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id VARCHAR(64) NOT NULL,
                step INTEGER NOT NULL,
                category VARCHAR(64) DEFAULT NULL,
                connectivity JSON DEFAULT NULL,
                min_rating INTEGER DEFAULT NULL,
                binding VARCHAR(16) DEFAULT NULL,
                owned_device_count INTEGER DEFAULT NULL,
                completed BOOLEAN NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->addSql('CREATE INDEX idx_wizard_session ON wizard_analytics(session_id)');
        $this->addSql('CREATE INDEX idx_wizard_category ON wizard_analytics(category)');
        $this->addSql('CREATE INDEX idx_wizard_created ON wizard_analytics(created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS wizard_analytics');
    }
}
