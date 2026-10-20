<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The token the game's page uses to send the statistics of the player to his account (?st= when JOUER opens
 * the game; the page keeps it): the account's id, signed with the site's secret. No cookie: the game is on
 * another domain (GitHub Pages).
 */
class StatsToken
{
    public function __construct(#[Autowire('%kernel.secret%')] private string $secret)
    {
    }

    public function create(User $user): string
    {
        return $user->getId() . '.' . $this->sign((string) $user->getId());
    }

    /** The account's id, or null when the token is not one of ours. */
    public function userId(string $token): ?int
    {
        if (!preg_match('/^(\d{1,10})\.([0-9a-f]{32})$/', $token, $m)) return null;

        return hash_equals($this->sign($m[1]), $m[2]) ? (int) $m[1] : null;
    }

    private function sign(string $id): string
    {
        return substr(hash_hmac('sha256', 'stats|' . $id, $this->secret), 0, 32);
    }
}
