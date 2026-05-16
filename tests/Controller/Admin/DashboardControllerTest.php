<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Browser\Test\HasBrowser;

class DashboardControllerTest extends KernelTestCase
{
    use HasBrowser;

    public function testAdminDashboardRequiresAuthentication(): void
    {
        $this->browser()
            ->interceptRedirects()
            ->visit('/admin')
            ->assertRedirectedTo('/login');
    }

    public function testAdminDashboardRequiresAdminRole(): void
    {
        $user = $this->createTestUser('nonadmin@example.com', 'testpassword123', ['ROLE_API']);

        try {
            $this->browser()
                ->actingAs($user)
                ->visit('/admin')
                ->assertStatus(403);
        } finally {
            $this->removeTestUser('nonadmin@example.com');
        }
    }

    public function testAdminDashboardAccessibleWithAdminRole(): void
    {
        $user = $this->createTestUser('admin@example.com', 'testpassword123', ['ROLE_ADMIN']);

        try {
            $this->browser()
                ->actingAs($user)
                ->visit('/admin')
                ->assertSuccessful()
                ->assertSeeIn('.admin-header h1', 'Admin Dashboard');
        } finally {
            $this->removeTestUser('admin@example.com');
        }
    }

    public function testAdminDashboardShowsStatistics(): void
    {
        $user = $this->createTestUser('statsadmin@example.com', 'testpassword123', ['ROLE_ADMIN']);

        try {
            $this->browser()
                ->actingAs($user)
                ->visit('/admin')
                ->assertSuccessful()
                ->assertSeeElement('.stats-grid');
        } finally {
            $this->removeTestUser('statsadmin@example.com');
        }
    }

    public function testAdminDashboardShowsUserTokens(): void
    {
        $user = $this->createTestUser('tokenadmin@example.com', 'testpassword123', ['ROLE_ADMIN']);

        /** @var ApiTokenRepository $tokenRepo */
        $tokenRepo = static::getContainer()->get(ApiTokenRepository::class);
        $apiToken = $tokenRepo->createForUser($user, 'Test Token');
        $tokenString = $apiToken->getToken();

        try {
            $this->browser()
                ->actingAs($user)
                ->visit('/admin')
                ->assertSuccessful()
                ->assertSeeElement('.tokens-table')
                ->assertSee('Test Token');
        } finally {
            $tokenToRemove = $tokenRepo->findValidToken($tokenString);
            if ($tokenToRemove) {
                $tokenRepo->remove($tokenToRemove, true);
            }
            $this->removeTestUser('tokenadmin@example.com');
        }
    }

    public function testAdminDashboardShowsNoTokensMessage(): void
    {
        $user = $this->createTestUser('notokenadmin@example.com', 'testpassword123', ['ROLE_ADMIN']);

        try {
            $this->browser()
                ->actingAs($user)
                ->visit('/admin')
                ->assertSuccessful()
                ->assertSee('No API tokens');
        } finally {
            $this->removeTestUser('notokenadmin@example.com');
        }
    }

    public function testAdminDashboardShowsMultipleTokens(): void
    {
        $user = $this->createTestUser('multitokenadmin@example.com', 'testpassword123', ['ROLE_ADMIN']);

        /** @var ApiTokenRepository $tokenRepo */
        $tokenRepo = static::getContainer()->get(ApiTokenRepository::class);
        $tokenStrings = [
            $tokenRepo->createForUser($user, 'Home Assistant')->getToken(),
            $tokenRepo->createForUser($user, 'CLI Tool')->getToken(),
            $tokenRepo->createForUser($user, 'Development')->getToken(),
        ];

        try {
            $this->browser()
                ->actingAs($user)
                ->visit('/admin')
                ->assertSuccessful()
                ->assertSee('Home Assistant')
                ->assertSee('CLI Tool')
                ->assertSee('Development');
        } finally {
            foreach ($tokenStrings as $tokenString) {
                $tokenToRemove = $tokenRepo->findValidToken($tokenString);
                if ($tokenToRemove) {
                    $tokenRepo->remove($tokenToRemove, true);
                }
            }
            $this->removeTestUser('multitokenadmin@example.com');
        }
    }

    /**
     * @param string[] $roles
     */
    private function createTestUser(string $email, string $password, array $roles = []): User
    {
        $container = static::getContainer();

        /** @var UserRepository $userRepository */
        $userRepository = $container->get(UserRepository::class);

        /** @var UserPasswordHasherInterface $passwordHasher */
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = (new User())
            ->setEmail($email)
            ->setRoles($roles);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $userRepository->save($user, true);

        return $user;
    }

    private function removeTestUser(string $email): void
    {
        $container = static::getContainer();

        /** @var UserRepository $userRepository */
        $userRepository = $container->get(UserRepository::class);

        $userToRemove = $userRepository->findByEmail($email);
        if ($userToRemove) {
            $userRepository->remove($userToRemove, true);
        }
    }
}
