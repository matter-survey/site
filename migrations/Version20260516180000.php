<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop spec_version from the clusters table. The field was an approximate
 * hand-seeded "first Matter version this cluster appeared in" — now derived
 * authoritatively in MatterRegistry from the earliest matter_version row in
 * cluster_versions (excluding master).
 */
final class Version20260516180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop spec_version from clusters (now derived from cluster_versions)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_clusters_spec_version');
        $this->addSql('ALTER TABLE clusters DROP COLUMN spec_version');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clusters ADD COLUMN spec_version VARCHAR(10) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_clusters_spec_version ON clusters (spec_version)');
    }
}
