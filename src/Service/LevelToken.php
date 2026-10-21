<?php

namespace App\Service;

use App\Entity\Level;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The key a JOUER link gives the game's page (?lt=, another domain: no cookie) so that it may download the
 * level even when it is private: its owner plays it while making it. Signed with the site's secret.
 */
final class LevelToken
{
    public function __construct(#[Autowire('%kernel.secret%')] private string $secret)
    {
    }

    public function create(Level $level): string
    {
        return substr(hash_hmac('sha256', 'level|' . $level->getId(), $this->secret), 0, 24);
    }

    public function isValid(Level $level, string $token): bool
    {
        return hash_equals($this->create($level), $token);
    }
}
