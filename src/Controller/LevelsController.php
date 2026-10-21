<?php

namespace App\Controller;

use App\Entity\Level;
use App\Entity\User;
use App\Repository\LevelRepository;
use App\Repository\UserRepository;
use App\Service\LevelRatings;
use App\Service\StatsToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The catalogue of the levels of the players (sorted, searched, page by page), their stars and the favourites:
 * on the site, and for the game (its menu NIVEAUX DU SITE asks with the account's token, StatsToken).
 */
final class LevelsController extends AbstractController
{
    private const PER_PAGE = 24;
    /** The orders of the page, as in its address (?tri=). */
    public const SORTS = ['top' => 'Mieux notés', 'new' => 'Nouveautés', 'plays' => 'Plus joués', 'fav' => 'Coups de cœur'];

    public function __construct(private LevelRepository $levels, private LevelRatings $ratings, private TranslatorInterface $translator)
    {
    }

    #[Route('/niveaux', name: 'levels', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser() instanceof User ? $this->getUser() : null;
        $sort = (string) $request->query->get('tri', 'top');
        $sort = isset(self::SORTS[$sort]) ? $sort : 'top';
        // ?vue=favoris: the favourites of the player, ?vue=miens: his levels
        $view = (string) $request->query->get('vue', '');
        $view = $user !== null && in_array($view, ['favoris', 'miens'], true) ? $view : '';
        $search = mb_substr(trim((string) $request->query->get('q', '')), 0, 40);
        $page = max(1, min(1000, $request->query->getInt('page', 1)));
        [$list, $total] = $this->levels->catalogue($sort, $page, self::PER_PAGE, $search, $view === 'favoris' ? $user : null, $view === 'miens' ? $user : null);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $pages && $total > 0) return $this->redirectToRoute('levels', ['tri' => $sort, 'vue' => $view ?: null, 'q' => $search ?: null, 'page' => $pages]);

        return $this->render('levels/index.html.twig', [
            'levels' => $list, 'total' => $total, 'page' => $page, 'pages' => $pages,
            'sort' => $sort, 'view' => $view, 'search' => $search, 'sorts' => self::SORTS,
        ]);
    }

    /** The stars of a level (form of its page: stars 1 to 5, 0 takes the vote back). */
    #[Route('/niveau/{id}/note', name: 'level_vote', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function vote(Level $level, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($this->isCsrfTokenValid('rating', (string) $request->request->get('_token'))) {
            $refused = $this->ratings->cannotVote($level, $user);
            if ($refused !== null) $this->addFlash('rating', $refused);
            else $this->ratings->vote($level, $user, max(0, min(5, $request->request->getInt('stars'))));
        }

        return $this->back($level, $request);
    }

    #[Route('/niveau/{id}/favori', name: 'level_favourite', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function favourite(Level $level, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($this->isCsrfTokenValid('rating', (string) $request->request->get('_token'))) {
            $this->ratings->favourite($level, $user, $request->request->getBoolean('on'));
        }

        return $this->back($level, $request);
    }

    /**
     * The game: what the player thinks of a level he just played ({st, id, stars?: 0-5, fav?: bool}, as text: no
     * CORS preflight). Answers the level's totals and his own vote, or {error}.
     */
    #[Route('/api/niveaux/note', name: 'api_level_vote', methods: ['POST'])]
    public function gameVote(Request $request, StatsToken $stats, UserRepository $users): JsonResponse
    {
        $body = json_decode(substr($request->getContent(), 0, 1024), true);
        $body = is_array($body) ? $body : [];
        $id = is_string($body['st'] ?? null) ? $stats->userId($body['st']) : null;
        $user = $id !== null ? $users->find($id) : null;
        $level = is_int($body['id'] ?? null) ? $this->levels->find($body['id']) : null;
        $response = new JsonResponse();
        $response->headers->set('Access-Control-Allow-Origin', '*');
        if ($user === null || $level === null || !$level->canSee($user)) return $response->setData(['error' => 'not found'])->setStatusCode(404);
        if (is_int($body['stars'] ?? null)) {
            $refused = $this->ratings->cannotVote($level, $user);
            if ($refused !== null) return $response->setData(['error' => $this->translator->trans($refused, [], null, 'en')])->setStatusCode(403);
            $this->ratings->vote($level, $user, max(0, min(5, $body['stars'])));
        }
        if (is_bool($body['fav'] ?? null)) $this->ratings->favourite($level, $user, $body['fav']);

        return $response->setData(['id' => $level->getId(), 'stars' => $this->ratings->myVote($level, $user), 'fav' => $this->ratings->isFavourite($level, $user),
            'rating' => $level->getRating(), 'votes' => $level->getVoteCount(), 'favourites' => $level->getFavourites(), 'plays' => $level->getPlays()]);
    }

    /** Back to the page the form was on (a page of this site only), else the level's. */
    private function back(Level $level, Request $request): Response
    {
        $back = (string) $request->request->get('back', '');
        if (str_starts_with($back, '/') && !str_starts_with($back, '//') && !str_contains($back, '\\')) return $this->redirect($back);

        return $this->redirectToRoute('level', ['id' => $level->getId()]);
    }
}
