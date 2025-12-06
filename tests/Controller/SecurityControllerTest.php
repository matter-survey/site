<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SecurityControllerTest extends WebTestCase
{
    public function testLoginPageIsAccessible(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
        $this->assertSelectorExists('input[name="_username"]');
        $this->assertSelectorExists('input[name="_password"]');
    }

    public function testLoginPageShowsTitle(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Sign In');
    }

    public function testLoginWithInvalidCredentials(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $form = $crawler->selectButton('Sign In')->form([
            '_username' => 'invalid@example.com',
            '_password' => 'wrongpassword',
        ]);

        $client->submit($form);

        // Should redirect back to login
        $this->assertResponseRedirects('/login');

        // Follow redirect and check for error
        $client->followRedirect();
        $this->assertSelectorExists('.login-error');
    }

    public function testLoginWithValidCredentials(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // Create a test user
        $user = $this->createTestUser($container, 'logintest@example.com', 'testpassword123', ['ROLE_ADMIN']);

        // Go to login page
        $crawler = $client->request('GET', '/login');
        $this->assertResponseIsSuccessful();

        // Submit login form
        $form = $crawler->selectButton('Sign In')->form([
            '_username' => 'logintest@example.com',
            '_password' => 'testpassword123',
        ]);

        $client->submit($form);

        // Should redirect to admin dashboard
        $this->assertResponseRedirects('/admin');

        // Follow redirect and verify we're on admin page
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.admin-header h1', 'Admin Dashboard');

        // Cleanup
        $this->removeTestUser($container, 'logintest@example.com');
    }

    public function testLoggedInUserIsRedirectedFromLogin(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // Create and login as test user
        $user = $this->createTestUser($container, 'redirecttest@example.com', 'testpassword123', ['ROLE_ADMIN']);
        $client->loginUser($user, 'main');

        // Try to access login page
        $client->request('GET', '/login');

        // Should redirect to admin dashboard
        $this->assertResponseRedirects('/admin');

        // Cleanup
        $this->removeTestUser($container, 'redirecttest@example.com');
    }

    public function testLogoutRedirectsToHomepage(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // Create and login as test user
        $user = $this->createTestUser($container, 'logouttest@example.com', 'testpassword123', ['ROLE_ADMIN']);
        $client->loginUser($user, 'main');

        // Access logout
        $client->request('GET', '/logout');

        // Should redirect (to homepage or login)
        $this->assertTrue($client->getResponse()->isRedirect());

        // Cleanup
        $this->removeTestUser($container, 'logouttest@example.com');
    }

    public function testRememberMeCheckboxExists(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="_remember_me"]');
    }

    public function testCsrfTokenIsPresent(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="_csrf_token"]');
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

    private function removeTestUser(\Psr\Container\ContainerInterface $container, string $email): void
    {
        /** @var UserRepository $userRepository */
        $userRepository = $container->get(UserRepository::class);

        // Re-fetch user to avoid detached entity error
        $userToRemove = $userRepository->findByEmail($email);
        if ($userToRemove) {
            $userRepository->remove($userToRemove, true);
        }
    }
}
