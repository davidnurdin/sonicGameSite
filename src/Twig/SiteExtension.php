<?php

namespace App\Twig;

use App\Service\OnlineStats;
use Twig\Attribute\AsTwigFunction;

/** For every page: the players in the game (the counter of the header and of the home page). */
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
}
