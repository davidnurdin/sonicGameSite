<?php

namespace App\Repository;

use App\Entity\Level;
use App\Entity\Tileset;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Tileset> */
class TilesetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tileset::class);
    }

    /** @return list<Tileset> the zones of the game, in their order */
    public function zones(): array
    {
        return $this->createQueryBuilder('t')->where('t.zone IS NOT NULL')->orderBy('t.id')->getQuery()->getResult();
    }

    /** @return list<Tileset> */
    public function ofUser(User $user): array
    {
        return $this->createQueryBuilder('t')->where('t.user = :user')->setParameter('user', $user)
            ->orderBy('t.updatedAt', 'DESC')->getQuery()->getResult();
    }

    /** @return list<Tileset> the public sets of the players (not the zones), the latest first */
    public function publicOnes(int $max = 60): array
    {
        return $this->createQueryBuilder('t')->addSelect('u')->join('t.user', 'u')->where('t.public = true')
            ->orderBy('t.updatedAt', 'DESC')->setMaxResults($max)->getQuery()->getResult();
    }

    /** What $user may build a level with: the zones, his sets, the public ones. */
    public function usable(?User $user): array
    {
        $own = $user ? $this->ofUser($user) : [];
        $public = array_filter($this->publicOnes(200), fn(Tileset $t) => !$t->isOwnedBy($user));

        return [...$this->zones(), ...$own, ...$public];
    }

    /** A set shown in a public level can be seen (its image) even when it is not public any more. */
    public function inPublicLevel(Tileset $tileset): bool
    {
        return (bool) $this->getEntityManager()->createQueryBuilder()->select('COUNT(l.id)')->from(Level::class, 'l')
            ->where('l.tileset = :t')->andWhere('l.public = true')->setParameter('t', $tileset)
            ->getQuery()->getSingleScalarResult();
    }

    public function usedBy(Tileset $tileset): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()->select('COUNT(l.id)')->from(Level::class, 'l')
            ->where('l.tileset = :t')->setParameter('t', $tileset)->getQuery()->getSingleScalarResult();
    }
}
