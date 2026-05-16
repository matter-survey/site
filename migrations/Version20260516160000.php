<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create cluster_versions table for per-Matter-version cluster spec snapshots.
 *
 * Each row is a (cluster_id, matter_version) tuple carrying the cluster's
 * upstream-derived spec at that Matter release: ClusterRevision number,
 * attributes/commands/features JSON, apiMaturity, and the upstream
 * description text. Hand-curated fields stay on the `clusters` table.
 */
final class Version20260516160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cluster_versions table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cluster_versions (
                cluster_id INTEGER NOT NULL,
                matter_version VARCHAR(10) NOT NULL,
                cluster_revision INTEGER DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                description CLOB DEFAULT NULL,
                api_maturity VARCHAR(20) DEFAULT NULL,
                attributes CLOB DEFAULT NULL,
                commands CLOB DEFAULT NULL,
                features CLOB DEFAULT NULL,
                PRIMARY KEY (cluster_id, matter_version)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_cluster_versions_version ON cluster_versions (matter_version)');
        $this->addSql('CREATE INDEX idx_cluster_versions_cluster ON cluster_versions (cluster_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cluster_versions');
    }
}
