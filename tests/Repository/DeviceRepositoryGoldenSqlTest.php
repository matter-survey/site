<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\DeviceRepository;
use App\Service\DatabaseService;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Golden-SQL pins for the two highest-risk SQLite-specific filter fragments:
 * the capability INTERSECT subquery and the connectivity JSON-array LIKE predicate.
 *
 * These assert the emitted SQL (whitespace-normalized) and bound parameters of the
 * private *Fragment() helpers, so a future edit that changes the SQL semantics or
 * parameter names fails here instead of silently altering behavior. Row-level
 * behavior is covered by DeviceRepositoryTest / DeviceRepositoryExtraTest.
 */
final class DeviceRepositoryGoldenSqlTest extends KernelTestCase
{
    private DeviceRepository $repository;
    private QueryBuilder $qb;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(DeviceRepository::class);
        $this->qb = self::getContainer()->get(DatabaseService::class)->getConnection()->createQueryBuilder();
    }

    public function testConnectivityLikeFragmentGoldenSql(): void
    {
        $fragment = $this->invokeFragment('connectivityLikeFragment', [$this->qb, ['wifi', 'thread']]);

        $this->assertSame(
            '(connectivity_types LIKE :conn_0 OR connectivity_types LIKE :conn_1)',
            $this->normalize($fragment),
        );
        $this->assertSame(
            ['conn_0' => '%"wifi"%', 'conn_1' => '%"thread"%'],
            $this->qb->getParameters(),
        );
    }

    public function testCapabilityIntersectFragmentGoldenSql(): void
    {
        // dimming → cluster 8, full_color → cluster 768; combined with INTERSECT.
        $fragment = $this->invokeFragment('capabilityIntersectFragment', [$this->qb, ['dimming', 'full_color']]);

        $this->assertNotNull($fragment);
        $this->assertSame(
            'id IN ( '
            .'SELECT DISTINCT pe.device_id FROM product_endpoints pe '
            .'WHERE EXISTS ( SELECT 1 FROM json_each(pe.server_clusters) WHERE value IN (:cap0_cl0) ) '
            .'INTERSECT '
            .'SELECT DISTINCT pe.device_id FROM product_endpoints pe '
            .'WHERE EXISTS ( SELECT 1 FROM json_each(pe.server_clusters) WHERE value IN (:cap1_cl0) ) '
            .')',
            $this->normalize($fragment),
        );
        $this->assertSame(
            ['cap0_cl0' => 8, 'cap1_cl0' => 768],
            $this->qb->getParameters(),
        );
    }

    public function testCapabilityIntersectFragmentIsNullWhenNoKnownKeys(): void
    {
        $this->assertNull($this->invokeFragment('capabilityIntersectFragment', [$this->qb, ['not_a_capability']]));
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invokeFragment(string $method, array $args): ?string
    {
        $ref = new \ReflectionMethod(DeviceRepository::class, $method);

        /** @var string|null $result */
        $result = $ref->invokeArgs($this->repository, $args);

        return $result;
    }

    private function normalize(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }
}
