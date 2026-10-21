<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The public API (/api/public): read by anybody, from any site (CORS, no cookie: there is nothing private in it),
 * within a limit by address (600 requests at once at most, then one a second). Its pages of documentation are not counted.
 */
final class PublicApiSubscriber implements EventSubscriberInterface
{
    public function __construct(#[Autowire(service: 'limiter.public_api')] private RateLimiterFactoryInterface $limiter)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // before the routing and API Platform's providers
        return [KernelEvents::REQUEST => ['onRequest', 64], KernelEvents::RESPONSE => 'onResponse'];
    }

    private static function concerns(Request $request): bool
    {
        return preg_match('#^/api/public(/|$)#', $request->getPathInfo()) === 1;
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::concerns($request)) return;
        if ($request->isMethod('OPTIONS')) {
            $event->setResponse(self::cors(new JsonResponse(null, 204)));

            return;
        }
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) return;   // API Platform says 405
        // the documentation (Swagger UI and its files): not counted
        if (preg_match('#^/api/public(/docs(\.[a-z]+)?|/?|/index(\.[a-z]+)?|/contexts/.*)$#', $request->getPathInfo()) === 1) return;
        $limit = $this->limiter->create((string) $request->getClientIp())->consume();
        // what is left, said on the answer (onResponse)
        $request->attributes->set('_public_api_limit', [$limit->getLimit(), $limit->getRemainingTokens()]);
        if (!$limit->isAccepted()) {
            $response = new JsonResponse(['title' => 'Too Many Requests', 'status' => 429,
                'detail' => 'Trop de requêtes depuis cette adresse : 600 d\'un coup au plus, puis une par seconde. Réessaie après Retry-After secondes.'], 429);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));
            $event->setResponse(self::cors($response));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !self::concerns($event->getRequest())) return;
        $response = self::cors($event->getResponse());
        if (is_array($limit = $event->getRequest()->attributes->get('_public_api_limit'))) {
            $response->headers->set('X-RateLimit-Limit', (string) $limit[0]);
            $response->headers->set('X-RateLimit-Remaining', (string) $limit[1]);
        }
    }

    private static function cors(\Symfony\Component\HttpFoundation\Response $response): \Symfony\Component\HttpFoundation\Response
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Accept, Content-Type');
        $response->headers->set('Access-Control-Expose-Headers', 'Link, Retry-After, X-RateLimit-Limit, X-RateLimit-Remaining');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}
