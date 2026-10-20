<?php

namespace App\Controller;

use App\Entity\PlayerStats;
use App\Entity\User;
use App\Repository\PlayerStatsRepository;
use App\Repository\UserRepository;
use App\Service\GamePresence;
use App\Service\StatsToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class StatsController extends AbstractController
{
    /** The most one sending may add (the page sends every minute, and when it is closed). */
    private const MAX = ['time_solo' => 21600, 'time_online' => 21600, 'time_versus' => 21600, 'jumps' => 50000,
        'rings' => 50000, 'enemies' => 10000, 'springs' => 20000, 'deaths' => 2000, 'acts' => 200, 'emeralds' => 60,
        'matches_online' => 200, 'wins_online' => 200, 'matches_versus' => 200, 'wins_versus' => 200];

    public function __construct(#[Autowire(env: 'GAME_URL')] private string $gameUrl)
    {
    }

    #[Route('/statistiques', name: 'stats', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function page(PlayerStatsRepository $repository): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('stats/index.html.twig', [
            'stats' => $repository->forUser($user),
            'top' => $repository->top(),
        ]);
    }

    /**
     * The game's page (GitHub Pages) sends what the player gained since its last sending: {"st": token, "d":
     * {key: amount}}, as text/plain (no CORS preflight; navigator.sendBeacon when the page is closed).
     */
    /** The game's page: "this tab has the game open" (every 45 s), {"id", "name", "bye"} as text/plain. */
    #[Route('/api/jeu', name: 'api_game', methods: ['POST', 'OPTIONS'])]
    public function game(Request $request, GamePresence $presence): Response
    {
        $response = $this->cors($request, new Response('', Response::HTTP_NO_CONTENT));
        if ($request->isMethod('OPTIONS')) return $response;
        $body = json_decode(substr($request->getContent(), 0, 512), true);
        $id = is_array($body) ? (string) ($body['id'] ?? '') : '';
        if (!preg_match('/^[a-z0-9]{6,20}$/', $id)) return $response->setStatusCode(Response::HTTP_BAD_REQUEST);
        $name = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($body['name'] ?? '')), 0, 8));
        $presence->beat($id, $name !== '' ? $name : null, !empty($body['bye']));

        return $response;
    }

    #[Route('/api/stats', name: 'api_stats', methods: ['POST', 'OPTIONS'])]
    public function receive(
        Request $request,
        StatsToken $tokens,
        UserRepository $users,
        PlayerStatsRepository $repository,
        EntityManagerInterface $em,
        #[Autowire(service: 'limiter.stats')] RateLimiterFactoryInterface $limiter,
    ): Response {
        $response = $this->cors($request, new Response('', Response::HTTP_NO_CONTENT));
        if ($request->isMethod('OPTIONS')) return $response;

        $body = json_decode(substr($request->getContent(), 0, 4096), true);
        $id = is_array($body) && is_string($body['st'] ?? null) ? $tokens->userId($body['st']) : null;
        $user = $id !== null ? $users->find($id) : null;
        if (!$user instanceof User) return $response->setStatusCode(Response::HTTP_FORBIDDEN);
        if (!$limiter->create((string) $user->getId())->consume()->isAccepted()) {
            return $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);
        }

        $delta = [];
        foreach (is_array($body['d'] ?? null) ? $body['d'] : [] as $key => $value) {
            if (!isset(self::MAX[$key]) || !is_numeric($value) || $value <= 0) continue;
            $delta[$key] = min((float) $value, self::MAX[$key]);
        }
        // a win comes with its match
        foreach (['online', 'versus'] as $kind) {
            if (isset($delta["wins_$kind"])) $delta["wins_$kind"] = min($delta["wins_$kind"], $delta["matches_$kind"] ?? 0);
        }
        $delta = array_filter($delta);
        if (!$delta) return $response;

        $stats = $repository->forUser($user);
        if ($stats === null) $em->persist($stats = new PlayerStats($user));
        $stats->add($delta);
        $em->flush();

        return $response;
    }

    /** The game's page may read the answer (it is on another domain). */
    private function cors(Request $request, Response $response): Response
    {
        $origin = $request->headers->get('Origin');
        if ($origin !== null && $this->allowedOrigin($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'POST');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->setVary('Origin');
        }

        return $response;
    }

    /** The game's site (GAME_URL), and the local test servers. */
    private function allowedOrigin(string $origin): bool
    {
        $game = parse_url($this->gameUrl);
        $gameOrigin = ($game['scheme'] ?? 'https') . '://' . ($game['host'] ?? '') . (isset($game['port']) ? ':' . $game['port'] : '');

        return $origin === $gameOrigin || (bool) preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $origin);
    }
}
