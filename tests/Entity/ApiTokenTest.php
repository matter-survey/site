<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ApiToken;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class ApiTokenTest extends TestCase
{
    public function testNewApiTokenHasDefaultValues(): void
    {
        $token = new ApiToken();

        $this->assertNull($token->getId());
        $this->assertNull($token->getToken());
        $this->assertNull($token->getName());
        $this->assertNull($token->getUser());
        $this->assertInstanceOf(\DateTimeImmutable::class, $token->getCreatedAt());
        $this->assertNull($token->getLastUsedAt());
        $this->assertNull($token->getExpiresAt());
    }

    public function testSetAndGetToken(): void
    {
        $token = new ApiToken();
        $token->setToken('ms_test_token_123');

        $this->assertEquals('ms_test_token_123', $token->getToken());
    }

    public function testSetAndGetName(): void
    {
        $token = new ApiToken();
        $token->setName('Home Assistant');

        $this->assertEquals('Home Assistant', $token->getName());
    }

    public function testSetAndGetUser(): void
    {
        $token = new ApiToken();
        $user = new User();
        $user->setEmail('test@example.com');

        $token->setUser($user);

        $this->assertSame($user, $token->getUser());
    }

    public function testSetAndGetLastUsedAt(): void
    {
        $token = new ApiToken();
        $lastUsed = new \DateTimeImmutable('2025-06-15 12:00:00');

        $token->setLastUsedAt($lastUsed);

        $this->assertEquals($lastUsed, $token->getLastUsedAt());
    }

    public function testSetAndGetExpiresAt(): void
    {
        $token = new ApiToken();
        $expiresAt = new \DateTimeImmutable('2026-01-01 00:00:00');

        $token->setExpiresAt($expiresAt);

        $this->assertEquals($expiresAt, $token->getExpiresAt());
    }

    public function testSetAndGetCreatedAt(): void
    {
        $token = new ApiToken();
        $createdAt = new \DateTimeImmutable('2025-01-01 00:00:00');

        $token->setCreatedAt($createdAt);

        $this->assertEquals($createdAt, $token->getCreatedAt());
    }

    public function testIsExpiredReturnsFalseWhenNoExpirationSet(): void
    {
        $token = new ApiToken();

        $this->assertFalse($token->isExpired());
    }

    public function testIsExpiredReturnsFalseWhenExpirationInFuture(): void
    {
        $token = new ApiToken();
        $futureDate = new \DateTimeImmutable('+1 year');
        $token->setExpiresAt($futureDate);

        $this->assertFalse($token->isExpired());
    }

    public function testIsExpiredReturnsTrueWhenExpirationInPast(): void
    {
        $token = new ApiToken();
        $pastDate = new \DateTimeImmutable('-1 day');
        $token->setExpiresAt($pastDate);

        $this->assertTrue($token->isExpired());
    }

    public function testIsValidReturnsTrueWhenNotExpired(): void
    {
        $token = new ApiToken();
        $futureDate = new \DateTimeImmutable('+1 year');
        $token->setExpiresAt($futureDate);

        $this->assertTrue($token->isValid());
    }

    public function testIsValidReturnsFalseWhenExpired(): void
    {
        $token = new ApiToken();
        $pastDate = new \DateTimeImmutable('-1 day');
        $token->setExpiresAt($pastDate);

        $this->assertFalse($token->isValid());
    }

    public function testIsValidReturnsTrueWhenNoExpiration(): void
    {
        $token = new ApiToken();

        $this->assertTrue($token->isValid());
    }

    public function testGenerateTokenHasCorrectFormat(): void
    {
        $token = ApiToken::generateToken();

        // Should start with 'ms_' prefix
        $this->assertStringStartsWith('ms_', $token);

        // Should be 68 characters total (3 for prefix + 64 hex chars)
        $this->assertEquals(67, strlen($token));

        // The hex part should only contain hex characters
        $hexPart = substr($token, 3);
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $hexPart);
    }

    public function testGenerateTokenIsUnique(): void
    {
        $tokens = [];
        for ($i = 0; $i < 100; ++$i) {
            $tokens[] = ApiToken::generateToken();
        }

        // All tokens should be unique
        $this->assertCount(100, array_unique($tokens));
    }

    public function testFluentInterface(): void
    {
        $token = new ApiToken();
        $user = new User();

        $result = $token
            ->setToken('ms_test')
            ->setName('Test Token')
            ->setUser($user)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setLastUsedAt(new \DateTimeImmutable())
            ->setExpiresAt(new \DateTimeImmutable('+1 year'));

        $this->assertSame($token, $result);
    }
}
