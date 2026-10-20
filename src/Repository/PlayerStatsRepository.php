<?php

namespace App\Repository;

use App\Entity\PlayerStats;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PlayerStats> */
class PlayerStatsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerStats::class);
    }

    public function forUser(User $user): ?PlayerStats
    {
        return $this->find($user);
    }

    /**
     * Seconds played by each of these players (those who have statistics).
     *
     * @param list<User> $users
     * @return array<int, float> user id => seconds
     */
    public function timesOf(array $users): array
    {
        if (!$users) return [];
        $times = [];
        foreach ($this->findBy(['user' => $users]) as $stats) $times[$stats->getUser()->getId()] = $stats->getTimePlayed();

        return $times;
    }

    /**
     * The players who played the most (all modes), for the ranking of the page Statistiques.
     *
     * @return list<array{name: string, time: float, wins: int}>
     */
    public function top(int $limit = 10): array
    {
        $rows = [];
        foreach ($this->findAll() as $stats) {
            $time = $stats->getTimePlayed();
            if ($time < 60) continue;
            $rows[] = ['name' => $stats->getUser()->getUsername(), 'time' => $time,
                'wins' => (int) ($stats->get('wins_online') + $stats->get('wins_versus'))];
        }
        usort($rows, fn(array $a, array $b) => $b['time'] <=> $a['time']);

        return array_slice($rows, 0, $limit);
    }
}
