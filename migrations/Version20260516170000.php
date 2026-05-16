<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop spec-data JSON columns from clusters now that ClusterVersion is the
 * authoritative source for per-Matter-version spec data (attributes, commands,
 * features, apiMaturity). The clusters table keeps only hand-curated
 * annotations + spec_version for now (spec_version drops in a follow-up
 * once its remaining consumers are migrated to derive from ClusterVersion).
 */
final class Version20260516170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop spec-data JSON columns from clusters (now sourced from cluster_versions)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clusters DROP COLUMN api_maturity');
        $this->addSql('ALTER TABLE clusters DROP COLUMN attributes');
        $this->addSql('ALTER TABLE clusters DROP COLUMN commands');
        $this->addSql('ALTER TABLE clusters DROP COLUMN features');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clusters ADD COLUMN api_maturity VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE clusters ADD COLUMN attributes CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE clusters ADD COLUMN commands CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE clusters ADD COLUMN features CLOB DEFAULT NULL');
    }
}
