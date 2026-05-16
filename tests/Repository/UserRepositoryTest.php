<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

class UserRepositoryTest extends KernelTestCase
{
    private UserRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = static::getContainer()->get(UserRepository::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function makeUser(string $email, array $roles = ['ROLE_USER']): User
    {
        return (new User())
            ->setEmail($email)
            ->setPassword('hashed-placeholder')
            ->setRoles($roles);
    }

    public function testSaveWithFlush(): void
    {
        $user = $this->makeUser('save@example.com');
        $this->repository->save($user, true);

        $this->assertNotNull($user->getId());
        $this->assertSame('save@example.com', $this->repository->findByEmail('save@example.com')?->getEmail());
    }

    public function testSaveWithoutFlushDefers(): void
    {
        $user = $this->makeUser('lazy@example.com');
        $this->repository->save($user, false);

        $this->entityManager->flush();
        $this->assertNotNull($user->getId());
    }

    public function testRemoveWithFlush(): void
    {
        $user = $this->makeUser('to-delete@example.com');
        $this->repository->save($user, true);
        $id = $user->getId();
        $this->assertNotNull($id);

        $this->repository->remove($user, true);

        $this->assertNull($this->repository->find($id));
    }

    public function testRemoveWithoutFlushDefers(): void
    {
        $user = $this->makeUser('lazy-delete@example.com');
        $this->repository->save($user, true);

        $this->repository->remove($user, false);
        $this->entityManager->flush();

        $this->assertNull($this->repository->findByEmail('lazy-delete@example.com'));
    }

    public function testFindAllAdminsReturnsOnlyAdminRoleUsers(): void
    {
        $this->repository->save($this->makeUser('admin1@example.com', ['ROLE_ADMIN']));
        $this->repository->save($this->makeUser('admin2@example.com', ['ROLE_ADMIN', 'ROLE_API']));
        $this->repository->save($this->makeUser('user1@example.com', ['ROLE_USER']));
        $this->entityManager->flush();

        $admins = $this->repository->findAllAdmins();

        $emails = array_map(fn (User $u) => $u->getEmail(), $admins);
        $this->assertContains('admin1@example.com', $emails);
        $this->assertContains('admin2@example.com', $emails);
        $this->assertNotContains('user1@example.com', $emails);
    }

    public function testUpgradePasswordPersistsNewHash(): void
    {
        $user = $this->makeUser('upgrade@example.com');
        $this->repository->save($user, true);

        $this->repository->upgradePassword($user, 'new-hashed-secret');

        $reloaded = $this->repository->findByEmail('upgrade@example.com');
        $this->assertSame('new-hashed-secret', $reloaded?->getPassword());
    }

    public function testUpgradePasswordRejectsForeignUserType(): void
    {
        $foreign = new class implements PasswordAuthenticatedUserInterface {
            public function getPassword(): ?string
            {
                return null;
            }
        };

        $this->expectException(UnsupportedUserException::class);
        $this->repository->upgradePassword($foreign, 'whatever');
    }
}
