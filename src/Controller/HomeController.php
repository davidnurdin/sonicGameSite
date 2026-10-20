<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\OnlineStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    public function __construct(#[Autowire(env: 'GAME_URL')] private string $gameUrl)
    {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(OnlineStats $stats): Response
    {
        return $this->render('home/index.html.twig', ['playUrl' => $this->playUrl(), 'online' => $stats->get()]);
    }

    /** JOUER: the game; signed in, with the account's name (the game uses it in the online rooms). */
    #[Route('/jouer', name: 'play', methods: ['GET'])]
    public function play(): Response
    {
        return $this->redirect($this->playUrl());
    }

    #[Route('/editeur', name: 'editor', methods: ['GET'])]
    public function editor(): Response
    {
        return $this->render('home/editor.html.twig');
    }

    private function playUrl(): string
    {
        $user = $this->getUser();

        return $user instanceof User ? $this->gameUrl . '?' . http_build_query(['name' => $user->getUsername()]) : $this->gameUrl;
    }
}
