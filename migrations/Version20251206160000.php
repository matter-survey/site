<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reset first_seen, last_seen, and submission_count for products without telemetry data.
 *
 * Products imported from DCL fixtures should not count as "seen" until they
 * are actually submitted through the telemetry survey.
 */
final class Version20251206160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reset seen timestamps and submission_count for products without telemetry data';
    }

    public function up(Schema $schema): void
    {
        // Reset products that have no telemetry data (no product_endpoints entries)
        // These are products imported from DCL fixtures that were never seen in telemetry
        $this->addSql('
            UPDATE products
            SET first_seen = NULL,
                last_seen = NULL,
                submission_count = 0
            WHERE id NOT IN (
                SELECT DISTINCT device_id FROM product_endpoints
            )
        ');
    }

    public function down(Schema $schema): void
    {
        // Cannot reliably restore the original values, but we can set reasonable defaults
        // for products that are now NULL
        $this->addSql('
            UPDATE products
            SET first_seen = CURRENT_TIMESTAMP,
                last_seen = CURRENT_TIMESTAMP,
                submission_count = 1
            WHERE first_seen IS NULL
        ');
    }
}
