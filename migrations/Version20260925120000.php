<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\Product;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Regenerate product slugs left malformed by the original SQL backfill.
 *
 * Version20251129170000 slugified product names in SQL and only stripped
 * spaces, "/", "&", "." and ",", so names like "Radiator Thermostat II [+M]"
 * or "FRITZ!Smart Gateway" kept characters the device_show route rejects
 * ([a-z0-9-]+), and every link to them 404'd. The survey upsert keeps an
 * existing slug (COALESCE), so those rows never healed on their own.
 *
 * Only rows whose slug falls outside the route's character set are touched;
 * valid slugs stay stable. Old URLs are 301-redirected by
 * DeviceController::showMalformedSlug.
 */
final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Regenerate product slugs containing characters outside [a-z0-9-]';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, vendor_id, product_id, product_name, slug FROM products');

        foreach ($rows as $row) {
            if (null !== $row['slug'] && 1 === preg_match('/^[a-z0-9-]+$/', (string) $row['slug'])) {
                continue;
            }

            $this->addSql('UPDATE products SET slug = :slug WHERE id = :id', [
                'slug' => Product::generateSlug($row['product_name'], (int) $row['vendor_id'], (int) $row['product_id']),
                'id' => (int) $row['id'],
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        // Irreversible data fix: the malformed slugs are not worth restoring.
    }
}
