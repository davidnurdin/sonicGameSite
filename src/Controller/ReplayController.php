<?php

namespace App\Controller;

use App\Entity\Level;
use App\Entity\Replay;
use App\Entity\User;
use App\Repository\LevelRepository;
use App\Repository\ReplayRepository;
use App\Repository\UserRepository;
use App\Service\Game;
use App\Service\LevelRecords;
use App\Service\StatsToken;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/** The history of the games and their replays ("Revoir": the game shows it again). */
class ReplayController extends AbstractController
{
    private const PER_PAGE = 25;
    /** The most a replay may weigh, gzip (15 minutes, 6 Sonics: about 2 MB). */
    private const MAX_SIZE = 4 * 1024 * 1024;
    /** The replays kept per account: the oldest go. */
    private const KEEP = 300;

    public function __construct(#[Autowire(env: 'GAME_URL')] private string $gameUrl, private LevelRepository $levels)
    {
    }

    #[Route('/historique', name: 'history', methods: ['GET'])]
    public function history(Request $request, ReplayRepository $replays, UserRepository $users): Response
    {
        $mine = $request->query->get('voir') === 'moi' && $this->getUser() instanceof User;
        // one player's games (from his profile)
        $name = $request->query->get('joueur');
        $player = $mine || !is_string($name) ? null : $users->findOneBy(['username' => strtoupper(substr($name, 0, 8))]);
        if ($player === $this->getUser() && $player !== null) [$mine, $player] = [true, null];
        $query = $mine ? ['voir' => 'moi'] : ($player ? ['joueur' => $player->getUsername()] : []);
        $page = max(1, $request->query->getInt('page', 1));
        [$list, $total] = $replays->page($mine ? $this->getUser() : $player, $page, self::PER_PAGE);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $pages) return $this->redirectToRoute('history', $query);

        return $this->render('replay/index.html.twig', [
            'replays' => $list,
            'mine' => $mine,
            'player' => $player,
            'query' => $query,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'modes' => Game::MODES,
            'watch' => $this->gameUrl . (str_contains($this->gameUrl, '?') ? '&' : '?') . http_build_query([
                'lang' => $request->getLocale(),
                'site' => $request->getSchemeAndHttpHost(),
                'back' => $request->getUri(),
            ]) . '&replay=',
        ]);
    }

    /** The replay itself, for the game's page (another domain): the gzip file as it was sent. */
    #[Route('/api/replay/{id}', name: 'api_replay_get', requirements: ['id' => '\d{1,10}'], methods: ['GET'])]
    public function get(Replay $replay): Response
    {
        $response = new Response($replay->getData(), Response::HTTP_OK, ['Content-Type' => 'application/gzip']);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->setPublic()->setMaxAge(86400);

        return $response;
    }

    /** The game's page sends the replay of a game that just ended: {"st": token, "r": base64 of the gzip}, text/plain. */
    #[Route('/api/replay', name: 'api_replay', methods: ['POST', 'OPTIONS'])]
    public function receive(
        Request $request,
        StatsToken $tokens,
        UserRepository $users,
        ReplayRepository $replays,
        EntityManagerInterface $em,
        #[Autowire(service: 'limiter.replay')] RateLimiterFactoryInterface $limiter,
        LevelRecords $records,
    ): Response {
        $response = $this->cors($request, new Response('', Response::HTTP_NO_CONTENT));
        if ($request->isMethod('OPTIONS')) return $response;

        $content = $request->getContent();
        if (strlen($content) > self::MAX_SIZE * 4 / 3 + 1024) return $response->setStatusCode(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        $body = json_decode($content, true);
        $id = is_array($body) && is_string($body['st'] ?? null) ? $tokens->userId($body['st']) : null;
        $user = $id !== null ? $users->find($id) : null;
        if (!$user instanceof User) return $response->setStatusCode(Response::HTTP_FORBIDDEN);
        if (!$limiter->create((string) $user->getId())->consume()->isAccepted()) {
            return $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);
        }

        $gzip = is_string($body['r'] ?? null) ? base64_decode($body['r'], true) : false;
        $replay = is_string($gzip) && strlen($gzip) <= self::MAX_SIZE ? $this->replay($user, $gzip) : null;
        if ($replay === null) return $response->setStatusCode(Response::HTTP_BAD_REQUEST);

        $em->persist($replay);
        try {
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            return $response;   // this match, sent again
        }
        // a level of the site completed alone: a best time, if it is his best on the level as it is now
        if ($replay->getKind() === 'solo' && $replay->getSiteLevel() !== null && ($time = $this->completedTime($gzip)) !== null) {
            $records->completed($replay->getSiteLevel(), $user, $time[1], $time[0], $replay);
        }
        $replays->keepLatest($user, self::KEEP);

        return $response->setStatusCode(Response::HTTP_CREATED);
    }

    /** The replay, if it is one (ReplayRecorder.php of the game): what the history shows is read from it. */
    private function replay(User $user, string $gzip): ?Replay
    {
        $json = @gzdecode($gzip, 64 * 1024 * 1024);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || ($data['v'] ?? null) !== 1 || !in_array($data['kind'] ?? null, Replay::KINDS, true)) return null;
        $kind = $data['kind'];
        $mode = (string) ($data['mode'] ?? '');
        if ($kind === 'solo' ? $mode !== 'solo' : !isset(Game::MODES[$mode])) return null;
        $level = $data['level'] ?? null;
        if (!is_int($level) || $level < 1 || $level > 40) return null;
        if (!is_array($data['entities'] ?? null) || !$data['entities'] || count($data['entities']) > 8) return null;
        if (!is_array($data['frames'] ?? null) || !$data['frames']) return null;
        $name = fn($v) => substr(preg_replace('/[^A-Z0-9 ]/', '', strtoupper((string) $v)), 0, 12);
        $players = array_map(fn($e) => $name(is_array($e) ? ($e['name'] ?? '') : ''), $data['entities']);
        $result = [];
        foreach (is_array($data['result'] ?? null) ? array_slice($data['result'], 0, 8) : [] as $row) {
            if (is_array($row) && count($row) >= 2) $result[] = [$name($row[0]), substr((string) $row[1], 0, 20)];
        }
        $match = $kind === 'online' && is_string($data['match'] ?? null) && preg_match('/^[0-9a-f]{12}$/', $data['match']) ? $data['match'] : null;
        $duration = min(3600.0, max(0.0, (float) ($data['duration'] ?? count($data['frames']) / 30)));

        $replay = new Replay($user, $kind, $mode, $level, $match, $players, $result, $duration, $gzip);
        // a level of the site: which one (its name in the history; gone, the replay can't be watched)
        if ($level === Game::SITE_LEVEL) {
            $site = is_array($data['site'] ?? null) && is_int($data['site']['id'] ?? null) ? $this->levels->find($data['site']['id']) : null;
            if (!$site instanceof Level) return null;
            $replay->setSiteLevel($site);
        }

        return $replay;
    }

    /**
     * The time of a level of the site completed alone (the game's timer: "time", or its tally "m:ss.cc") and the
     * version of the level it was played on (site.version), or null.
     *
     * @return array{0: float, 1: string}|null
     */
    private function completedTime(string $gzip): ?array
    {
        $data = json_decode((string) @gzdecode($gzip, 64 * 1024 * 1024), true);
        $version = is_array($data) ? ($data['site']['version'] ?? null) : null;
        if (!is_string($version) || !preg_match('/^[0-9a-f]{16}$/', $version)) return null;
        $time = $data['time'] ?? null;
        if (!is_float($time) && !is_int($time) && preg_match('/^(\d{1,2}):(\d\d(?:\.\d{1,2})?)$/', (string) ($data['result'][0][1] ?? ''), $m)) {
            $time = (int) $m[1] * 60 + (float) $m[2];
        }

        return is_float($time) || is_int($time) ? [round((float) $time, 2), $version] : null;
    }

    /** The game's page may read the answer (it is on another domain): see StatsController. */
    private function cors(Request $request, Response $response): Response
    {
        $origin = $request->headers->get('Origin');
        $game = parse_url($this->gameUrl);
        $gameOrigin = ($game['scheme'] ?? 'https') . '://' . ($game['host'] ?? '') . (isset($game['port']) ? ':' . $game['port'] : '');
        if ($origin !== null && ($origin === $gameOrigin || preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $origin))) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'POST');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');
            $response->setVary('Origin');
        }

        return $response;
    }
}
