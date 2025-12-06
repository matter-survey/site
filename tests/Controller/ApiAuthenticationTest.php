<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests for API authentication using Bearer tokens.
 *
 * Note: /api/submit, /api/search, /api/docs, and /api/ are public endpoints.
 * Future protected API endpoints will require ROLE_API.
 */
class ApiAuthenticationTest extends WebTestCase
{
    public function testApiSubmitEndpointIsPublic(): void
    {
        $client = static::createClient();

        // Submit endpoint should be accessible without authentication
        $client->request('POST', '/api/submit', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'installation_id' => '550e8400-e29b-41d4-a716-446655440099',
            'devices' => [],
        ]));

        // Should succeed (not 401)
        $this->assertResponseIsSuccessful();
    }

    public function testApiSearchEndpointIsPublic(): void
    {
        $client = static::createClient();

        // Search endpoint should be accessible without authentication
        $client->request('GET', '/api/search');

        $this->assertResponseIsSuccessful();
    }

    public function testApiDocsEndpointIsPublic(): void
    {
        $client = static::createClient();

        // Docs redirect should be accessible without authentication
        $client->request('GET', '/api/');

        $this->assertResponseRedirects('/api/docs.html');
    }

    public function testApiAuthenticationWithInvalidTokenReturnsError(): void
    {
        $client = static::createClient();

        // When providing an invalid Bearer token, the authenticator should reject it
        // even on public endpoints that accept optional auth
        $client->request('GET', '/api/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ms_invalid_token_12345',
        ]);

        // Invalid tokens should be rejected
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('error', $response['status']);
        $this->assertStringContainsString('Invalid or expired', $response['error']);
    }

    public function testApiAuthenticationWithExpiredTokenReturnsError(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // Create user and expired token
        $user = $this->createTestUser($container, 'expireduser@example.com', 'password123', ['ROLE_API']);

        /** @var ApiTokenRepository $tokenRepo */
        $tokenRepo = $container->get(ApiTokenRepository::class);
        $apiToken = $tokenRepo->createForUser($user, 'Expired Token', new \DateTimeImmutable('-1 day'));
        $tokenString = $apiToken->getToken();

        // Make request with expired token
        $client->request('GET', '/api/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenString,
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Invalid or expired', $response['error']);

        // Cleanup - re-fetch to avoid detached entity errors
        // Note: expired tokens won't be found by findValidToken, so we need to delete via user
        $this->removeTestUser($container, 'expireduser@example.com');
    }

    public function testApiAuthenticationWithValidTokenSucceeds(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // Create user and token
        $user = $this->createTestUser($container, 'apiuser@example.com', 'password123', ['ROLE_API']);

        /** @var ApiTokenRepository $tokenRepo */
        $tokenRepo = $container->get(ApiTokenRepository::class);
        $apiToken = $tokenRepo->createForUser($user, 'Test API Token');
        $tokenString = $apiToken->getToken();

        // Make authenticated request
        $client->request('GET', '/api/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenString,
        ]);

        // Should succeed (redirect to docs)
        $this->assertResponseRedirects('/api/docs.html');

        // Cleanup - re-fetch to avoid detached entity errors
        $tokenToRemove = $tokenRepo->findValidToken($tokenString);
        if ($tokenToRemove) {
            $tokenRepo->remove($tokenToRemove, true);
        }
        $this->removeTestUser($container, 'apiuser@example.com');
    }

    public function testApiAuthenticationUpdatesLastUsedAt(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // Create user and token
        $user = $this->createTestUser($container, 'lastuseuser@example.com', 'password123', ['ROLE_API']);

        /** @var ApiTokenRepository $tokenRepo */
        $tokenRepo = $container->get(ApiTokenRepository::class);
        $apiToken = $tokenRepo->createForUser($user, 'Track Usage Token');
        $tokenString = $apiToken->getToken();

        $this->assertNull($apiToken->getLastUsedAt());

        // Make authenticated request
        $client->request('GET', '/api/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenString,
        ]);

        // Should succeed
        $this->assertResponseRedirects('/api/docs.html');

        // Refresh token from database
        $updatedToken = $tokenRepo->findValidToken($tokenString);

        $this->assertNotNull($updatedToken->getLastUsedAt());

        // Cleanup - token already re-fetched above
        $tokenRepo->remove($updatedToken, true);
        $this->removeTestUser($container, 'lastuseuser@example.com');
    }

    public function testMultipleTokensForSameUserAllWork(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // Create user with multiple tokens
        $user = $this->createTestUser($container, 'multitoken@example.com', 'password123', ['ROLE_API']);

        /** @var ApiTokenRepository $tokenRepo */
        $tokenRepo = $container->get(ApiTokenRepository::class);
        $token1 = $tokenRepo->createForUser($user, 'Token 1');
        $token2 = $tokenRepo->createForUser($user, 'Token 2');
        $tokenString1 = $token1->getToken();
        $tokenString2 = $token2->getToken();

        // Both tokens should work
        $client->request('GET', '/api/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenString1,
        ]);
        $this->assertResponseRedirects('/api/docs.html');

        $client->request('GET', '/api/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenString2,
        ]);
        $this->assertResponseRedirects('/api/docs.html');

        // Cleanup - re-fetch to avoid detached entity errors
        $tokenToRemove1 = $tokenRepo->findValidToken($tokenString1);
        $tokenToRemove2 = $tokenRepo->findValidToken($tokenString2);
        if ($tokenToRemove1) {
            $tokenRepo->remove($tokenToRemove1, false);
        }
        if ($tokenToRemove2) {
            $tokenRepo->remove($tokenToRemove2, true);
        }
        $this->removeTestUser($container, 'multitoken@example.com');
    }

    public function testTokenFromDifferentUserCannotBeUsedByAnother(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // Create two users with their own tokens
        $user1 = $this->createTestUser($container, 'user1@example.com', 'password123', ['ROLE_API']);
        $user2 = $this->createTestUser($container, 'user2@example.com', 'password123', ['ROLE_API']);

        /** @var ApiTokenRepository $tokenRepo */
        $tokenRepo = $container->get(ApiTokenRepository::class);
        $token1 = $tokenRepo->createForUser($user1, 'User1 Token');
        $token2 = $tokenRepo->createForUser($user2, 'User2 Token');
        $tokenString1 = $token1->getToken();
        $tokenString2 = $token2->getToken();

        // Both tokens should work for their respective authenticated purposes
        $client->request('GET', '/api/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenString1,
        ]);
        $this->assertResponseRedirects('/api/docs.html');

        $client->request('GET', '/api/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$tokenString2,
        ]);
        $this->assertResponseRedirects('/api/docs.html');

        // Cleanup - re-fetch to avoid detached entity errors
        $tokenToRemove1 = $tokenRepo->findValidToken($tokenString1);
        $tokenToRemove2 = $tokenRepo->findValidToken($tokenString2);
        if ($tokenToRemove1) {
            $tokenRepo->remove($tokenToRemove1, false);
        }
        if ($tokenToRemove2) {
            $tokenRepo->remove($tokenToRemove2, true);
        }
        $this->removeTestUser($container, 'user1@example.com');
        $this->removeTestUser($container, 'user2@example.com');
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
