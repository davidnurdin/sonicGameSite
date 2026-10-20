<?php

namespace App\Entity;

use App\Repository\PlayerStatsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A player's statistics, added up from what the game sends (the web page of the game, POST /api/stats): seconds
 * played, jumps, rings... (the game's var/stats.json, see Stats.php in sonicGamePhp).
 */
#[ORM\Entity(repositoryClass: PlayerStatsRepository::class)]
#[ORM\Table(name: 'player_stats')]
class PlayerStats
{
    /** Seconds (one decimal), then counts: the keys of the game's file. */
    public const TIMES = ['time_solo', 'time_online', 'time_versus'];
    public const COUNTS = ['jumps', 'rings', 'enemies', 'springs', 'deaths', 'acts', 'emeralds',
        'matches_online', 'wins_online', 'matches_versus', 'wins_versus'];

    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** @var array<string, int|float> key => total */
    #[ORM\Column(type: 'json')]
    private array $totals = [];

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function get(string $key): float
    {
        return (float) ($this->totals[$key] ?? 0);
    }

    /** Seconds played, all modes. */
    public function getTimePlayed(): float
    {
        return array_sum(array_map($this->get(...), self::TIMES));
    }

    /** @param array<string, float> $delta what was gained since the last sending (already checked) */
    public function add(array $delta): void
    {
        foreach ($delta as $key => $value) {
            $this->totals[$key] = in_array($key, self::TIMES, true)
                ? round($this->get($key) + $value, 1) : (int) ($this->get($key) + $value);
        }
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
