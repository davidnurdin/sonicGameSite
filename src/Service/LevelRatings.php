<?php

namespace App\Service;

use App\Entity\Level;
use App\Entity\LevelFavourite;
use App\Entity\LevelVote;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What the players think of the levels: their stars (1 to 5, not on their own levels), their favourites, and how
 * many times each level was played. Level keeps the totals, to sort the catalogue (LevelRepository::catalogue).
 */
final class LevelRatings
{
    /** The score of a level is its average pulled towards PRIOR stars as if it had PRIOR_VOTES votes more. */
    public const PRIOR = 3.0;
    public const PRIOR_VOTES = 4;
    /** A player's plays of a level are counted once in this time (seconds). */
    private const PLAY_WINDOW = 6 * 3600;

    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire(service: 'cache.app')] private CacheItemPoolInterface $cache,
    ) {
    }

    public static function score(int $votes, int $sum): float
    {
        return round(($sum + self::PRIOR * self::PRIOR_VOTES) / ($votes + self::PRIOR_VOTES), 4);
    }

    /** Why $user may not give stars to $level (a message to translate), or null. */
    public function cannotVote(Level $level, ?User $user): ?string
    {
        return match (true) {
            $user === null => 'Connecte-toi pour noter les niveaux.',
            $level->isOwnedBy($user) => 'On ne note pas ses propres niveaux.',
            !$level->isPublic() || $level->isTrashed() => 'Seuls les niveaux publics se notent.',
            default => null,
        };
    }

    /** $user gives $stars (1 to 5) to $level, or takes his vote back (0). */
    public function vote(Level $level, User $user, int $stars): void
    {
        if ($this->cannotVote($level, $user) !== null) return;
        $vote = $this->em->getRepository(LevelVote::class)->findOneBy(['level' => $level, 'user' => $user]);
        if ($stars < 1) {
            if ($vote !== null) $this->em->remove($vote);
        } elseif ($vote === null) {
            $this->em->persist(new LevelVote($level, $user, $stars));
        } else {
            $vote->setStars($stars);
        }
        $this->em->flush();
        $this->refresh($level);
    }

    public function myVote(Level $level, ?User $user): int
    {
        if ($user === null || $level->getId() === null) return 0;

        return $this->em->getRepository(LevelVote::class)->findOneBy(['level' => $level, 'user' => $user])?->getStars() ?? 0;
    }

    /** @var array<int, array<int, true>> user => the ids of his favourites (one query a page) */
    private array $favouriteIds = [];

    /** @return array<int, true> the ids of the favourite levels of $user */
    public function favouriteIds(User $user): array
    {
        return $this->favouriteIds[$user->getId()] ??= array_fill_keys(array_map('intval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT level_id FROM level_favourite WHERE user_id = ?', [$user->getId()])), true);
    }

    public function isFavourite(Level $level, ?User $user): bool
    {
        return $user !== null && $this->em->getRepository(LevelFavourite::class)->findOneBy(['level' => $level, 'user' => $user]) !== null;
    }

    /** $level in the favourites of $user (one he can see), or out of them. */
    public function favourite(Level $level, User $user, bool $on): void
    {
        $favourite = $this->em->getRepository(LevelFavourite::class)->findOneBy(['level' => $level, 'user' => $user]);
        if ($on && $favourite === null && $level->canSee($user)) {
            $this->em->persist(new LevelFavourite($level, $user));
        } elseif (!$on && $favourite !== null) {
            $this->em->remove($favourite);
        } else {
            return;
        }
        $this->em->flush();
        unset($this->favouriteIds[$user->getId()]);
        $this->refresh($level);
    }

    /** The game downloads $level to play it: one more play, once in a while for the same player ($who: his address). */
    public function played(Level $level, string $who): void
    {
        $item = $this->cache->getItem('play_' . hash('sha256', $who . '|' . $level->getId()));
        if ($item->isHit()) return;
        $this->cache->save($item->set(1)->expiresAfter(self::PLAY_WINDOW));
        $this->em->getConnection()->executeStatement('UPDATE level SET plays = plays + 1 WHERE id = ?', [$level->getId()]);
    }

    /** The totals of $level, counted again. */
    private function refresh(Level $level): void
    {
        $connection = $this->em->getConnection();
        [$votes, $sum] = array_map('intval', array_values($connection->fetchAssociative(
            'SELECT COUNT(*), COALESCE(SUM(stars), 0) FROM level_vote WHERE level_id = ?', [$level->getId()])));
        $favourites = (int) $connection->fetchOne('SELECT COUNT(*) FROM level_favourite WHERE level_id = ?', [$level->getId()]);
        $level->setRatings($votes, $sum, self::score($votes, $sum), $favourites);
        // only these columns: the level's updatedAt is the date of its last change by its makers
        $connection->executeStatement('UPDATE level SET vote_count = ?, vote_sum = ?, score = ?, favourites = ? WHERE id = ?',
            [$votes, $sum, $level->getScore(), $favourites, $level->getId()]);
    }
}
