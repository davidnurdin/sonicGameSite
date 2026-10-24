<?php

namespace App\Twig;

use App\Service\Game;
use App\Service\OnlineStats;
use App\Service\VisitorCounter;
use Twig\Attribute\AsTwigFunction;

/**
 * For every page: the players in the game (the counter of the header and of the home page), the visitors since the
 * start (the footer), the names of the acts.
 */
class SiteExtension
{
    public function __construct(private OnlineStats $online, private VisitorCounter $visitors)
    {
    }

    /** @return array{count: int, since: \DateTimeImmutable} */
    #[AsTwigFunction('site_visitors')]
    public function visitors(): array
    {
        return ['count' => $this->visitors->count(), 'since' => new \DateTimeImmutable(VisitorCounter::SINCE)];
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
