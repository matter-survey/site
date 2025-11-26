<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove bridged node endpoints from the database.
 *
 * Bridged Node (device type 19 / 0x0013) endpoints represent devices from other
 * protocols (Z-Wave, Zigbee, etc.) that are bridged through a Matter device.
 * These are user-specific configurations and create noise in the data.
 */
final class Version20251126110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove bridged node (device type 19) endpoints from product_endpoints table';
    }

    public function up(Schema $schema): void
    {
        // Delete endpoints where device_types contains an object with id=19 (Bridged Node)
        // Device types are stored as JSON array of objects: [{"id": 19, "revision": 1}, ...]
        $this->addSql('
            DELETE FROM product_endpoints
            WHERE EXISTS (
                SELECT 1 FROM json_each(device_types)
                WHERE json_extract(value, "$.id") = 19
            )
        ');
    }

    public function down(Schema $schema): void
    {
        // Cannot restore deleted data - this is a one-way cleanup migration
        $this->addSql('SELECT 1'); // No-op
    }
}
