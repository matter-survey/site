<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class DashboardControllerTest extends WebTestCase
{
    public function testAdminDashboardRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        // Should redirect to login
        $this->assertResponseRedirects('/login');
    }

    public function testAdminDashboardRequiresAdminRole(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // Create a user without ROLE_ADMIN
        $user = $this->createTestUser($container, 'nonadmin@example.com', 'testpassword123', ['ROLE_API']);
        $client->loginUser($user, 'main');

        $client->request('GET', '/admin');

        // Should be forbidden
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // Cleanup
        $this->removeTestUser($container, $user);
    }

    public function testAdminDashboardAccessibleWithAdminRole(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // Create an admin user
        $user = $this->createTestUser($container, 'admin@example.com', 'testpassword123', ['ROLE_ADMIN']);

        // Log in the user using the main firewall
        $client->loginUser($user, 'main');

        $crawler = $client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.admin-header h1', 'Admin Dashboard');

        // Cleanup
        $this->removeTestUser($container, $user);
    }

    public function testAdminDashboardShowsStatistics(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $user = $this->createTestUser($container, 'statsadmin@example.com', 'testpassword123', ['ROLE_ADMIN']);
        $client->loginUser($user, 'main');

        $crawler = $client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();

        // Should show statistics section
        $this->assertSelectorExists('.stats-grid');

        // Cleanup
        $this->removeTestUser($container, $user);
    }

    public function testAdminDashboardShowsUserTokens(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // Create admin with an API token
        $user = $this->createTestUser($container, 'tokenadmin@example.com', 'testpassword123', ['ROLE_ADMIN']);

        /** @var ApiTokenRepository $tokenRepo */
        $tokenRepo = $container->get(ApiTokenRepository::class);
        $apiToken = $tokenRepo->createForUser($user, 'Test Token');
        $tokenString = $apiToken->getToken();

        $client->loginUser($user, 'main');

        $crawler = $client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();

        // Should show token section
        $this->assertSelectorExists('.tokens-table');

        // Should display the token name
        $this->assertStringContainsString('Test Token', $client->getResponse()->getContent());

        // Cleanup - re-fetch token to avoid detached entity error
        $tokenToRemove = $tokenRepo->findValidToken($tokenString);
        if ($tokenToRemove) {
            $tokenRepo->remove($tokenToRemove, true);
        }
        $this->removeTestUser($container, $user);
    }

    public function testAdminDashboardShowsNoTokensMessage(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // Create admin without any tokens
        $user = $this->createTestUser($container, 'notokenadmin@example.com', 'testpassword123', ['ROLE_ADMIN']);
        $client->loginUser($user, 'main');

        $crawler = $client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();

        // Should show "no tokens" message
        $this->assertStringContainsString('No API tokens', $client->getResponse()->getContent());

        // Cleanup
        $this->removeTestUser($container, $user);
    }

    public function testAdminDashboardShowsMultipleTokens(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // Create admin with multiple API tokens
        $user = $this->createTestUser($container, 'multitokenadmin@example.com', 'testpassword123', ['ROLE_ADMIN']);

        /** @var ApiTokenRepository $tokenRepo */
        $tokenRepo = $container->get(ApiTokenRepository::class);
        $token1 = $tokenRepo->createForUser($user, 'Home Assistant');
        $token2 = $tokenRepo->createForUser($user, 'CLI Tool');
        $token3 = $tokenRepo->createForUser($user, 'Development');

        // Store token strings for cleanup
        $tokenStrings = [$token1->getToken(), $token2->getToken(), $token3->getToken()];

        $client->loginUser($user, 'main');

        $crawler = $client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        $this->assertStringContainsString('Home Assistant', $content);
        $this->assertStringContainsString('CLI Tool', $content);
        $this->assertStringContainsString('Development', $content);

        // Cleanup - re-fetch tokens to avoid detached entity error
        foreach ($tokenStrings as $tokenString) {
            $tokenToRemove = $tokenRepo->findValidToken($tokenString);
            if ($tokenToRemove) {
                $tokenRepo->remove($tokenToRemove, true);
            }
        }
        $this->removeTestUser($container, $user);
    }

    private function createTestUser(\Psr\Container\ContainerInterface $container, string $email, string $password, array $roles = []): User
    {
        /** @var UserRepository $userRepository */
        $userRepository = $container->get(UserRepository::class);

        /** @var UserPasswordHasherInterface $passwordHasher */
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $userRepository->save($user, true);

        return $user;
    }

    private function removeTestUser(\Psr\Container\ContainerInterface $container, User $user): void
    {
        /** @var UserRepository $userRepository */
        $userRepository = $container->get(UserRepository::class);

        // Re-fetch user to avoid detached entity error
        $userToRemove = $userRepository->findByEmail($user->getEmail());
        if ($userToRemove) {
            $userRepository->remove($userToRemove, true);
        }
    }
}
