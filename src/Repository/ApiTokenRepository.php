<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    /**
     * Find a valid (non-expired) token and its user.
     */
    public function findValidToken(string $token): ?ApiToken
    {
        $apiToken = $this->findOneBy(['token' => $token]);

        if (null === $apiToken) {
            return null;
        }

        if ($apiToken->isExpired()) {
            return null;
        }

        return $apiToken;
    }

    /**
     * Find token and update its last used timestamp.
     */
    public function findAndUpdateLastUsed(string $token): ?ApiToken
    {
        $apiToken = $this->findValidToken($token);

        if (null === $apiToken) {
            return null;
        }

        $apiToken->setLastUsedAt(new \DateTimeImmutable());
        $this->getEntityManager()->flush();

        return $apiToken;
    }

    /**
     * @return ApiToken[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }

    public function save(ApiToken $apiToken, bool $flush = false): void
    {
        $this->getEntityManager()->persist($apiToken);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ApiToken $apiToken, bool $flush = false): void
    {
        $this->getEntityManager()->remove($apiToken);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Create a new API token for a user.
     */
    public function createForUser(User $user, string $name, ?\DateTimeImmutable $expiresAt = null): ApiToken
    {
        $apiToken = new ApiToken();
        $apiToken->setToken(ApiToken::generateToken());
        $apiToken->setName($name);
        $apiToken->setUser($user);

        if (null !== $expiresAt) {
            $apiToken->setExpiresAt($expiresAt);
        }

        $this->save($apiToken, true);

        return $apiToken;
    }
}
