<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill slugs for products that don't have one.
 */
final class Version20251129231337 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill slugs for products missing them';
    }

    public function up(Schema $schema): void
    {
        // Fetch all products without a slug
        $products = $this->connection->executeQuery('
            SELECT id, product_name, vendor_id, product_id
            FROM products
            WHERE slug IS NULL OR slug = ""
        ')->fetchAllAssociative();

        foreach ($products as $product) {
            $slug = $this->generateSlug(
                $product['product_name'],
                (int) $product['vendor_id'],
                (int) $product['product_id']
            );

            $this->addSql(
                'UPDATE products SET slug = ? WHERE id = ?',
                [$slug, $product['id']]
            );
        }
    }

    public function down(Schema $schema): void
    {
        // No rollback needed - slugs are useful
    }

    private function generateSlug(?string $productName, int $vendorId, int $productId): string
    {
        $slug = '';

        if (!empty($productName)) {
            $slug = strtolower($productName);
            $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
            $slug = preg_replace('/[\s_]+/', '-', $slug);
            $slug = preg_replace('/-+/', '-', $slug);
            $slug = trim($slug, '-');
        }

        // Always append vendor_id and product_id for uniqueness
        if ('' !== $slug) {
            return $slug.'-'.$vendorId.'-'.$productId;
        }

        return 'product-'.$vendorId.'-'.$productId;
    }
}
