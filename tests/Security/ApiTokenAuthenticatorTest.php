<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Security\ApiTokenAuthenticator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

class ApiTokenAuthenticatorTest extends TestCase
{
    private ApiTokenAuthenticator $authenticator;
    private ApiTokenRepository&MockObject $tokenRepository;

    protected function setUp(): void
    {
        $this->tokenRepository = $this->createMock(ApiTokenRepository::class);
        $this->authenticator = new ApiTokenAuthenticator($this->tokenRepository);
    }

    public function testSupportsReturnsTrueWithValidBearerHeader(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer ms_test_token');

        $this->assertTrue($this->authenticator->supports($request));
    }

    public function testSupportsReturnsFalseWithoutAuthorizationHeader(): void
    {
        $request = new Request();

        $this->assertFalse($this->authenticator->supports($request));
    }

    public function testSupportsReturnsFalseWithNonBearerAuth(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Basic dXNlcjpwYXNz');

        $this->assertFalse($this->authenticator->supports($request));
    }

    public function testSupportsReturnsFalseWithEmptyBearerPrefix(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Basic token');

        $this->assertFalse($this->authenticator->supports($request));
    }

    public function testAuthenticateSucceedsWithValidToken(): void
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setRoles(['ROLE_API']);

        $apiToken = new ApiToken();
        $apiToken->setToken('ms_valid_token');
        $apiToken->setUser($user);

        $this->tokenRepository
            ->expects($this->once())
            ->method('findAndUpdateLastUsed')
            ->with('ms_valid_token')
            ->willReturn($apiToken);

        $request = new Request();
        $request->headers->set('Authorization', 'Bearer ms_valid_token');

        $passport = $this->authenticator->authenticate($request);

        $this->assertEquals('test@example.com', $passport->getUser()->getUserIdentifier());
    }

    public function testAuthenticateThrowsExceptionWithEmptyToken(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer ');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('No API token provided');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWithInvalidToken(): void
    {
        $this->tokenRepository
            ->expects($this->once())
            ->method('findAndUpdateLastUsed')
            ->with('ms_invalid_token')
            ->willReturn(null);

        $request = new Request();
        $request->headers->set('Authorization', 'Bearer ms_invalid_token');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Invalid or expired API token');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenTokenHasNoUser(): void
    {
        $apiToken = new ApiToken();
        $apiToken->setToken('ms_orphan_token');
        // No user set

        $this->tokenRepository
            ->expects($this->once())
            ->method('findAndUpdateLastUsed')
            ->with('ms_orphan_token')
            ->willReturn($apiToken);

        $request = new Request();
        $request->headers->set('Authorization', 'Bearer ms_orphan_token');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('API token has no associated user');

        $this->authenticator->authenticate($request);
    }

    public function testOnAuthenticationSuccessReturnsNull(): void
    {
        $request = new Request();
        $token = $this->createMock(TokenInterface::class);

        $result = $this->authenticator->onAuthenticationSuccess($request, $token, 'api');

        $this->assertNull($result);
    }

    public function testOnAuthenticationFailureReturnsJsonError(): void
    {
        $request = new Request();
        $exception = new CustomUserMessageAuthenticationException('Test error message');

        $response = $this->authenticator->onAuthenticationFailure($request, $exception);

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $this->assertJson($response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('Test error message', $data['error']);
    }

    public function testStartReturnsUnauthorizedResponse(): void
    {
        $request = new Request();

        $response = $this->authenticator->start($request);

        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $this->assertJson($response->getContent());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertStringContainsString('Authentication required', $data['error']);
    }
}
