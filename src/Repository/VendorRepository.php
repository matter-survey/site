<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Vendor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Vendor>
 */
class VendorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vendor::class);
    }

    public function save(Vendor $vendor, bool $flush = false): void
    {
        $this->getEntityManager()->persist($vendor);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findBySlug(string $slug): ?Vendor
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function findBySpecId(int $specId): ?Vendor
    {
        return $this->findOneBy(['specId' => $specId]);
    }

    /**
     * Find or create a vendor by spec_id.
     */
    public function findOrCreateBySpecId(int $specId, ?string $name = null): Vendor
    {
        $vendor = $this->findBySpecId($specId);

        if (null !== $vendor) {
            // Update name if provided and different
            if (null !== $name && $name !== $vendor->getName()) {
                $vendor->setName($name);
                $vendor->setUpdatedAt(new \DateTime());
            }

            return $vendor;
        }

        // Create new vendor
        $vendor = new Vendor();
        $vendor->setSpecId($specId);
        $vendor->setName($name ?? "Vendor $specId");
        $vendor->setSlug(Vendor::generateSlug($name ?? '', $specId));
        $vendor->setDeviceCount(0);

        $this->save($vendor);

        return $vendor;
    }

    /**
     * Get all vendors ordered by device count (most popular first).
     *
     * @return Vendor[]
     */
    public function findAllOrderedByDeviceCount(): array
    {
        return $this->createQueryBuilder('v')
            ->orderBy('v.deviceCount', 'DESC')
            ->addOrderBy('v.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get popular vendors (with most devices).
     *
     * @return Vendor[]
     */
    public function findPopular(int $limit = 10): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.deviceCount > 0')
            ->orderBy('v.deviceCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Update device count for a vendor.
     */
    public function updateDeviceCount(Vendor $vendor): void
    {
        $conn = $this->getEntityManager()->getConnection();

        $count = $conn->executeQuery(
            'SELECT COUNT(*) FROM products WHERE vendor_fk = :vendorId',
            ['vendorId' => $vendor->getId()]
        )->fetchOne();

        $vendor->setDeviceCount((int) $count);
        $vendor->setUpdatedAt(new \DateTime());
    }
}
