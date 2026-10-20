<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Live visitor count (the Sonic of the home page background): each page subscribes to TOPIC on the Mercure
 * hub, and follows the subscriptions to it (the hub's subscription events and API). This route gives the
 * cookie that allows it, valid one hour (public/js/presence.js asks again when it expires).
 */
class PresenceController extends AbstractController
{
    public const TOPIC = 'urn:sonicphp:presence';

    #[Route('/presence', name: 'presence', methods: ['GET'])]
    public function token(Request $request, Authorization $authorization, HubInterface $hub): JsonResponse
    {
        $user = $this->getUser();
        $match = rawurlencode(self::TOPIC);
        // tells the page which of the subscriptions is its own
        $key = bin2hex(random_bytes(8));
        $subscriptions = '/.well-known/mercure/subscriptions/exact/' . $match;

        $authorization->setCookie($request, [
            // the visit itself; the payload (the account's name or nothing, the key) is what the others see of it
            new Grant([Grant::ACTION_SUBSCRIBE], [self::TOPIC], ['name' => $user instanceof User ? $user->getUsername() : null, 'k' => $key]),
            // who else is here: the list (API), then the arrivals and departures (events)
            new Grant([Grant::ACTION_SUBSCRIBE], [$subscriptions]),
            new Grant([Grant::ACTION_SUBSCRIBE], ['urlpattern' => [$subscriptions . '/:subscriber']]),
        ], null, ['exp' => new \DateTimeImmutable('+1 hour')]);

        $response = new JsonResponse([
            'hub' => $hub->getPublicUrl(),
            'topic' => self::TOPIC,
            'list' => $subscriptions,
            'events' => $subscriptions . '/:subscriber',
            'key' => $key,
        ]);
        $response->setPrivate();

        return $response;
    }
}
