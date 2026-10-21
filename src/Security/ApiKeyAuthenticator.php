<?php

namespace App\Security;

use App\Entity\ApiKey;
use App\Repository\ApiKeyRepository;
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
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * The API of the studio (/mcp, /api/v1): a player's key (ApiKey) in "Authorization: Bearer sk_sonic_...", no
 * cookie, no session. The key used is in the request's attribute `api_key`.
 */
final class ApiKeyAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private ApiKeyRepository $keys, private EntityManagerInterface $em)
    {
    }

    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $header = (string) $request->headers->get('Authorization');
        $key = str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : trim((string) $request->headers->get('X-Api-Key'));
        $apiKey = $key !== '' ? $this->keys->findByKey($key) : null;
        if ($apiKey === null) throw new CustomUserMessageAuthenticationException('Missing or unknown API key (Authorization: Bearer sk_sonic_...).');
        if ($apiKey->isExpired()) throw new CustomUserMessageAuthenticationException('This API key has expired: make a new one on the site.');
        $request->attributes->set('api_key', $apiKey);
        $apiKey->used();
        $this->em->flush();
        $user = $apiKey->getUser();

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), fn() => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return self::unauthorized($exception->getMessageKey());
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::unauthorized('An API key is needed (Authorization: Bearer sk_sonic_...).');
    }

    private static function unauthorized(string $message): JsonResponse
    {
        $response = new JsonResponse(['error' => 'unauthorized', 'message' => $message], 401);
        $response->headers->set('WWW-Authenticate', 'Bearer realm="sonic-php"');

        return $response;
    }
}
