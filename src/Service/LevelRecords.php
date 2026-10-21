<?php

namespace App\Service;

use App\Entity\Level;
use App\Entity\LevelRecord;
use App\Entity\Replay;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** The best times of the levels of the site: one by player, on the level as it is now (Level::getContentVersion). */
final class LevelRecords
{
    public const TOP = 10;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * A solo replay of a level of the site, completed in $time by $user on its version $version: his record if
     * he did better (true), when $version is the level's version now.
     */
    public function completed(Level $level, User $user, string $version, float $time, ?Replay $replay): bool
    {
        if ($time <= 0 || $time > 3600 || $version !== $level->getContentVersion()) return false;
        $record = $this->em->getRepository(LevelRecord::class)->findOneBy(['level' => $level, 'user' => $user, 'version' => $version]);
        if ($record !== null && $record->getTime() <= $time) return false;
        if ($record === null) $this->em->persist(new LevelRecord($level, $user, $version, $time, $replay));
        else $record->improve($time, $replay);
        $this->em->flush();

        return true;
    }

    /** @return list<LevelRecord> the best times of the level now, the fastest first (the older first when equal) */
    public function top(Level $level, int $count = self::TOP): array
    {
        if ($level->getId() === null) return [];

        return $this->em->getRepository(LevelRecord::class)->createQueryBuilder('r')->addSelect('u')->join('r.user', 'u')
            ->where('r.level = :level AND r.version = :version')->setParameter('level', $level)->setParameter('version', $level->getContentVersion())
            ->orderBy('r.time', 'ASC')->addOrderBy('r.createdAt', 'ASC')->setMaxResults($count)->getQuery()->getResult();
    }

    /** The best time of $user on the level now, or null. */
    public function of(Level $level, ?User $user): ?LevelRecord
    {
        if ($user === null || $level->getId() === null) return null;

        return $this->em->getRepository(LevelRecord::class)->findOneBy(['level' => $level, 'user' => $user, 'version' => $level->getContentVersion()]);
    }

    /** "1:02.35" */
    public static function format(float $time): string
    {
        return sprintf('%d:%05.2f', intdiv((int) $time, 60), fmod($time, 60));
    }
}
