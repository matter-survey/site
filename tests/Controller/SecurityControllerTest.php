<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Browser\Test\HasBrowser;

final class SecurityControllerTest extends KernelTestCase
{
    use HasBrowser;

    public function testLoginPageIsAccessible(): void
    {
        $this->browser()
            ->visit('/login')
            ->assertSuccessful()
            ->assertSeeElement('form')
            ->assertSeeElement('input[name="_username"]')
            ->assertSeeElement('input[name="_password"]');
    }

    public function testLoginPageShowsTitle(): void
    {
        $this->browser()
            ->visit('/login')
            ->assertSuccessful()
            ->assertSeeIn('h2', 'Sign In');
    }

    public function testLoginWithInvalidCredentials(): void
    {
        $this->browser()
            ->interceptRedirects()
            ->visit('/login')
            ->fillField('_username', 'invalid@example.com')
            ->fillField('_password', 'wrongpassword')
            ->click('Sign In')
            ->assertRedirectedTo('/login')
            ->assertSeeElement('.login-error');
    }

    public function testLoginWithValidCredentials(): void
    {
        $this->createTestUser('logintest@example.com', 'testpassword123', ['ROLE_ADMIN']);

        try {
            $this->browser()
                ->interceptRedirects()
                ->visit('/login')
                ->assertSuccessful()
                ->fillField('_username', 'logintest@example.com')
                ->fillField('_password', 'testpassword123')
                ->click('Sign In')
                ->assertRedirectedTo('/admin')
                ->assertSeeIn('.admin-header h1', 'Admin Dashboard');
        } finally {
            $this->removeTestUser('logintest@example.com');
        }
    }

    public function testLoggedInUserIsRedirectedFromLogin(): void
    {
        $user = $this->createTestUser('redirecttest@example.com', 'testpassword123', ['ROLE_ADMIN']);

        try {
            $this->browser()
                ->actingAs($user)
                ->interceptRedirects()
                ->visit('/login')
                ->assertRedirectedTo('/admin');
        } finally {
            $this->removeTestUser('redirecttest@example.com');
        }
    }

    public function testLogoutRedirectsToHomepage(): void
    {
        $user = $this->createTestUser('logouttest@example.com', 'testpassword123', ['ROLE_ADMIN']);

        try {
            $this->browser()
                ->actingAs($user)
                ->interceptRedirects()
                ->visit('/logout')
                ->assertRedirected();
        } finally {
            $this->removeTestUser('logouttest@example.com');
        }
    }

    public function testRememberMeCheckboxExists(): void
    {
        $this->browser()
            ->visit('/login')
            ->assertSuccessful()
            ->assertSeeElement('input[name="_remember_me"]');
    }

    public function testCsrfTokenIsPresent(): void
    {
        $this->browser()
            ->visit('/login')
            ->assertSuccessful()
            ->assertSeeElement('input[name="_csrf_token"]');
    }

    /**
     * @param string[] $roles
     */
    private function createTestUser(string $email, string $password, array $roles = []): User
    {
        $container = self::getContainer();

        /** @var UserRepository $userRepository */
        $userRepository = $container->get(UserRepository::class);

        /** @var UserPasswordHasherInterface $passwordHasher */
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User()
            ->setEmail($email)
            ->setRoles(array_values($roles));
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $userRepository->save($user, true);

        return $user;
    }

    private function removeTestUser(string $email): void
    {
        $container = self::getContainer();

        /** @var UserRepository $userRepository */
        $userRepository = $container->get(UserRepository::class);

        $userToRemove = $userRepository->findByEmail($email);
        if ($userToRemove) {
            $userRepository->remove($userToRemove, true);
        }
    }
}
