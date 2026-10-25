<?php

namespace App\Controller;

use App\Entity\Level;
use App\Entity\User;
use App\Repository\LevelRepository;
use App\Repository\UserRepository;
use App\Service\Game;
use App\Service\LevelToken;
use App\Service\OnlineStats;
use App\Service\StatsToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class HomeController extends AbstractController
{
    public function __construct(
        #[Autowire(env: 'GAME_URL')] private string $gameUrl,
        #[Autowire(env: 'RELAY_WS_URL')] private string $relayUrl,
        private StatsToken $statsToken,
        private LevelRepository $levels,
        private LevelToken $levelTokens,
    ) {
    }

    /** Visitors: what the game is. Signed in: the rooms (lobby). */
    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(Request $request, OnlineStats $online, UserRepository $users): Response
    {
        if ($this->getUser() instanceof User) {
            return $this->render('home/lobby.html.twig', [
                'playUrl' => $this->playUrl($request),
                'online' => $online->get(),
                'members' => $users->count([]),
                'modes' => Game::MODES,
                'levels' => Game::levels(),
            ]);
        }

        return $this->render('home/index.html.twig', [
            'playUrl' => $this->playUrl($request),
            'online' => $online->get(),
            'members' => $users->count([]),
        ]);
    }

    /** JOUER: the game, with the account's name (the online rooms) and its token; no account: sign up first. */
    #[Route('/jouer', name: 'play', methods: ['GET'])]
    public function play(Request $request): Response
    {
        if (!$this->getUser() instanceof User) return $this->redirectToRoute('register', ['next' => $this->generateUrl('play')]);

        return $this->redirect($this->playUrl($request));
    }

    /** The players online and the public rooms (the lobby asks every few seconds). */
    #[Route('/salons.json', name: 'rooms_json', methods: ['GET'])]
    public function rooms(OnlineStats $online): JsonResponse
    {
        $response = new JsonResponse(($online->get() ?? ['players' => null, 'watchers' => null, 'list' => []]) + ['ingame' => $online->inGame()]);
        $response->setPrivate();

        return $response;
    }

    /** A new room (public or private): the page of the room creates it on the relay. */
    #[Route('/salon/nouveau', name: 'room_new', methods: ['GET'], priority: 1)]
    #[IsGranted('ROLE_USER')]
    public function newRoom(Request $request): Response
    {
        $mode = (string) $request->query->get('mode', 'race');
        $level = $request->query->getInt('level', 1);

        return $this->renderRoom($request, null, [
            'public' => $request->query->get('type') !== 'prive',
            'mode' => isset(Game::MODES[$mode]) ? $mode : 'race',
            'level' => in_array($level, Game::LEVELS, true) ? $level : 1,
        ]);
    }

    /** The code of a private room (form of the lobby). */
    #[Route('/salon/rejoindre', name: 'room_join', methods: ['GET'], priority: 1)]
    public function joinRoom(Request $request): Response
    {
        $code = strtoupper(trim((string) $request->query->get('code', '')));
        if (!preg_match('/^[A-Z]{4}$/', $code)) return $this->redirectToRoute('home');

        return $this->redirectToRoute('room', ['code' => $code]);
    }

    /** A room: its players, the members of the site in it, its chat, JOUER (the game straight into the room). */
    #[Route('/salon/{code}', name: 'room', requirements: ['code' => '[A-Za-z]{4}'], methods: ['GET'])]
    public function room(Request $request, string $code): Response
    {
        if ($code !== strtoupper($code)) return $this->redirectToRoute('room', ['code' => strtoupper($code)]);

        return $this->renderRoom($request, $code, null);
    }

    private function renderRoom(Request $request, ?string $code, ?array $create): Response
    {
        $user = $this->getUser();
        // the levels of the site's editor a room may play: the account's own, then the public ones
        [$levels] = $this->levels->gamePage($user instanceof User ? $user : null, 1, 100);
        $siteLevels = array_map(fn(Level $level) => [
            'id' => $level->getId(),
            'name' => $level->getName(),
            'author' => $level->getUser()->getUsername(),
            'lt' => $level->isPublic() ? '' : $this->levelTokens->create($level),
        ], $levels);

        return $this->render('room/index.html.twig', [
            'code' => $code,
            'config' => [
                'relay' => $this->relayUrl,
                'room' => $code,
                'create' => $create,
                'name' => $user instanceof User ? $user->getUsername() : null,
                'play' => $this->playUrl($request),
                'site' => $request->getSchemeAndHttpHost(),
                'roomPath' => $this->generateUrl('room', ['code' => 'CODE']),
                'version' => OnlineStats::VERSION,
                'modes' => Game::MODES,
                'levels' => Game::levels(),
                'siteLevel' => Game::SITE_LEVEL,
                'siteLevels' => $siteLevels,
            ],
        ]);
    }

    /** The game: the account's name, the language of the site, the token of the statistics. */
    private function playUrl(Request $request): string
    {
        $user = $this->getUser();
        $query = ['lang' => $request->getLocale()];
        if ($user instanceof User) {
            $query += ['name' => $user->getUsername(), 'st' => $this->statsToken->create($user), 'site' => $request->getSchemeAndHttpHost()];
        }

        return $this->gameUrl . (str_contains($this->gameUrl, '?') ? '&' : '?') . http_build_query($query);
    }
}
