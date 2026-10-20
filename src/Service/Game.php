<?php

namespace App\Service;

/** What the site knows of the game's matches: the modes and the acts that can be played online (Versus.php). */
final class Game
{
    public const MODES = ['race' => 'Course', 'flag' => 'Drapeau', 'rings' => 'Anneaux', 'deathmatch' => 'Deathmatch'];
    public const LEVELS = [1, 2, 4, 5, 7, 8, 10, 11, 13, 14, 16, 17, 28, 29, 30, 31, 32, 33, 34, 35];
    private const ZONES = ['Green Hill', 'Bridge', 'Jungle', 'Labyrinth', 'Scrap Brain', 'Sky Base'];

    /** "Green Hill 1", or [bonus, n] for the special stages (translated by the template). */
    public static function levelName(int $level): string
    {
        if ($level >= 28) return 'Bonus ' . ($level - 27);

        return self::ZONES[intdiv(max(1, $level) - 1, 3)] . ' ' . ((max(1, $level) - 1) % 3 + 1);
    }

    /** @return array<int, string> level => name */
    public static function levels(): array
    {
        return array_combine(self::LEVELS, array_map(self::levelName(...), self::LEVELS));
    }
}
