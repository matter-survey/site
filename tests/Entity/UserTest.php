<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ApiToken;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testNewUserHasDefaultValues(): void
    {
        $user = new User();

        $this->assertNull($user->getId());
        $this->assertNull($user->getEmail());
        $this->assertNull($user->getPassword());
        $this->assertEmpty($user->getApiTokens());
        $this->assertInstanceOf(\DateTimeImmutable::class, $user->getCreatedAt());
        $this->assertNull($user->getLastLoginAt());
    }

    public function testSetAndGetEmail(): void
    {
        $user = new User();
        $user->setEmail('test@example.com');

        $this->assertEquals('test@example.com', $user->getEmail());
        $this->assertEquals('test@example.com', $user->getUserIdentifier());
    }

    public function testSetAndGetPassword(): void
    {
        $user = new User();
        $user->setPassword('hashed_password');

        $this->assertEquals('hashed_password', $user->getPassword());
    }

    public function testGetRolesAlwaysIncludesRoleUser(): void
    {
        $user = new User();

        // Even with no roles set, ROLE_USER should be included
        $this->assertContains('ROLE_USER', $user->getRoles());
    }

    public function testSetRoles(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN', 'ROLE_API']);

        $roles = $user->getRoles();
        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertContains('ROLE_API', $roles);
        $this->assertContains('ROLE_USER', $roles);
    }

    public function testRolesAreUnique(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_USER']);

        $roles = $user->getRoles();
        $this->assertCount(2, $roles); // ROLE_USER and ROLE_ADMIN only
    }

    public function testIsAdminReturnsTrueForAdminRole(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN']);

        $this->assertTrue($user->isAdmin());
    }

    public function testIsAdminReturnsFalseWithoutAdminRole(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_API']);

        $this->assertFalse($user->isAdmin());
    }

    public function testHasApiAccessReturnsTrueForApiRole(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_API']);

        $this->assertTrue($user->hasApiAccess());
    }

    public function testHasApiAccessReturnsTrueForAdminRole(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN']);

        $this->assertTrue($user->hasApiAccess());
    }

    public function testHasApiAccessReturnsFalseWithoutApiOrAdminRole(): void
    {
        $user = new User();
        $user->setRoles([]);

        $this->assertFalse($user->hasApiAccess());
    }

    public function testAddApiToken(): void
    {
        $user = new User();
        $token = new ApiToken();

        $user->addApiToken($token);

        $this->assertCount(1, $user->getApiTokens());
        $this->assertTrue($user->getApiTokens()->contains($token));
        $this->assertSame($user, $token->getUser());
    }

    public function testAddApiTokenDoesNotAddDuplicates(): void
    {
        $user = new User();
        $token = new ApiToken();

        $user->addApiToken($token);
        $user->addApiToken($token);

        $this->assertCount(1, $user->getApiTokens());
    }

    public function testRemoveApiToken(): void
    {
        $user = new User();
        $token = new ApiToken();

        $user->addApiToken($token);
        $user->removeApiToken($token);

        $this->assertCount(0, $user->getApiTokens());
        $this->assertNull($token->getUser());
    }

    public function testSetAndGetLastLoginAt(): void
    {
        $user = new User();
        $loginTime = new \DateTimeImmutable('2025-01-15 10:30:00');

        $user->setLastLoginAt($loginTime);

        $this->assertEquals($loginTime, $user->getLastLoginAt());
    }

    public function testSetAndGetCreatedAt(): void
    {
        $user = new User();
        $createdAt = new \DateTimeImmutable('2025-01-01 00:00:00');

        $user->setCreatedAt($createdAt);

        $this->assertEquals($createdAt, $user->getCreatedAt());
    }

    public function testEraseCredentialsDoesNotThrow(): void
    {
        $user = new User();
        $user->setPassword('test');

        // Should not throw an exception
        $user->eraseCredentials();

        $this->assertTrue(true);
    }

    public function testFluentInterface(): void
    {
        $user = new User();

        $result = $user
            ->setEmail('test@example.com')
            ->setPassword('password')
            ->setRoles(['ROLE_ADMIN'])
            ->setCreatedAt(new \DateTimeImmutable())
            ->setLastLoginAt(new \DateTimeImmutable());

        $this->assertSame($user, $result);
    }
}
