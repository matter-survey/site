<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\ApiTokenRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

class ApiTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    private const HEADER_NAME = 'Authorization';
    private const TOKEN_PREFIX = 'Bearer ';

    public function __construct(
        private ApiTokenRepository $apiTokenRepository,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->headers->has(self::HEADER_NAME)
            && str_starts_with($request->headers->get(self::HEADER_NAME, ''), self::TOKEN_PREFIX);
    }

    public function authenticate(Request $request): Passport
    {
        $authHeader = $request->headers->get(self::HEADER_NAME, '');
        $token = substr($authHeader, strlen(self::TOKEN_PREFIX));

        if ('' === $token) {
            throw new CustomUserMessageAuthenticationException('No API token provided');
        }

        // Find and validate the token, also updates last_used_at
        $apiToken = $this->apiTokenRepository->findAndUpdateLastUsed($token);

        if (null === $apiToken) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired API token');
        }

        $user = $apiToken->getUser();
        if (null === $user) {
            throw new CustomUserMessageAuthenticationException('API token has no associated user');
        }

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), fn () => $user)
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Continue to the controller
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(
            [
                'status' => 'error',
                'error' => strtr($exception->getMessageKey(), $exception->getMessageData()),
            ],
            Response::HTTP_UNAUTHORIZED
        );
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(
            [
                'status' => 'error',
                'error' => 'Authentication required. Provide a valid API token in the Authorization header.',
            ],
            Response::HTTP_UNAUTHORIZED
        );
    }
}
