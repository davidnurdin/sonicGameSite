<?php

namespace App\Twig;

use App\Service\Game;
use App\Service\OnlineStats;
use Twig\Attribute\AsTwigFunction;

/** For every page: the players in the game (the counter of the header and of the home page), the names of the acts. */
class SiteExtension
{
    public function __construct(private OnlineStats $online)
    {
    }

    #[AsTwigFunction('online_ingame')]
    public function inGame(): int
    {
        return $this->online->inGame();
    }

    #[AsTwigFunction('level_name')]
    public function levelName(int $level): string
    {
        return Game::levelName($level);
    }
}
