<?php

namespace App\Controller;

use App\Repository\PlayerStatsRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MembersController extends AbstractController
{
    private const PER_PAGE = 30;

    /** The members of the site: their name (the one of the game), since when, the time they played. */
    #[Route('/joueurs', name: 'members', methods: ['GET'])]
    public function list(Request $request, UserRepository $users, PlayerStatsRepository $stats): Response
    {
        $total = $users->count([]);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = $request->query->getInt('page', 1);
        if ($page < 1 || $page > $pages) return $this->redirectToRoute('members');
        $members = $users->page($page, self::PER_PAGE);

        return $this->render('members/index.html.twig', [
            'members' => $members,
            'times' => $stats->timesOf($members),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'first' => ($page - 1) * self::PER_PAGE + 1,
        ]);
    }
}
