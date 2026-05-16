<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add api_maturity column to clusters so the cluster-level spec lifecycle stage
 * (provisional / deprecated) from upstream ZAP XML can be surfaced in the UI.
 */
final class Version20260516145500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add api_maturity column to clusters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clusters ADD COLUMN api_maturity VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clusters DROP COLUMN api_maturity');
    }
}
