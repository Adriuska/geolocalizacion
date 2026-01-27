<?php

namespace App\Security;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
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

class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    public function supports(Request $request): ?bool
    {
        // No aplicar autenticación en rutas públicas
        $path = $request->getPathInfo();
        if (
            str_starts_with($path, '/api/register') ||
            str_starts_with($path, '/api/login') ||
            str_starts_with($path, '/api/test')
        ) {
            return false;
        }

        return $request->headers->has('Authorization') || $request->headers->has('X-API-TOKEN');
    }

    public function authenticate(Request $request): Passport
    {
        $apiToken = $request->headers->get('Authorization');

        if (!$apiToken) {
            $apiToken = $request->headers->get('X-API-TOKEN');
        }

        if (!$apiToken) {
            throw new CustomUserMessageAuthenticationException('No API token provided');
        }

        // Remove "Bearer " prefix if present
        if (str_starts_with($apiToken, 'Bearer ')) {
            $apiToken = substr($apiToken, 7);
        }

        $tokenRepo = $this->entityManager->getRepository(ApiToken::class);
        $token = $tokenRepo->findOneBy(['token' => $apiToken]);

        if (!$token) {
            throw new CustomUserMessageAuthenticationException('Invalid API token');
        }

        if ($token->isExpired()) {
            throw new CustomUserMessageAuthenticationException('API token expired');
        }

        // Actualizar actividad del usuario
        $user = $token->getUser();
        $user->updateActivity();
        $this->entityManager->flush();

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), function () use ($user) {
                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'success' => false,
            'error' => $exception->getMessageKey(),
            'data' => null,
            'metadata' => ['timestamp' => (new \DateTime())->format('c')]
        ], Response::HTTP_UNAUTHORIZED);
    }
}
