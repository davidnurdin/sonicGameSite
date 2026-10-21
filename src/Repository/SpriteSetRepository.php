<?php

namespace App\Repository;

use App\Entity\Level;
use App\Entity\SpriteSet;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SpriteSet> */
class SpriteSetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpriteSet::class);
    }

    /** @return list<SpriteSet> */
    public function ofUser(User $user): array
    {
        return $this->createQueryBuilder('s')->where('s.user = :user AND s.trashedAt IS NULL')->setParameter('user', $user)
            ->orderBy('s.updatedAt', 'DESC')->getQuery()->getResult();
    }

    /** @return list<SpriteSet> the public sets, the latest first */
    public function publicOnes(int $max = 60): array
    {
        return $this->createQueryBuilder('s')->addSelect('u')->join('s.user', 'u')->where('s.public = true AND s.trashedAt IS NULL')
            ->orderBy('s.updatedAt', 'DESC')->setMaxResults($max)->getQuery()->getResult();
    }

    /** What $user may use in a level: his sets, the public ones. */
    public function usable(?User $user): array
    {
        $own = $user ? $this->ofUser($user) : [];
        $public = array_filter($this->publicOnes(200), fn(SpriteSet $s) => !$s->isOwnedBy($user));

        return [...$own, ...$public];
    }

    /** A set used in a public level can be seen even when it is not public any more. */
    public function inPublicLevel(SpriteSet $set): bool
    {
        return (bool) $this->getEntityManager()->createQueryBuilder()->select('COUNT(l.id)')->from(Level::class, 'l')
            ->where('l.spriteSet = :s')->andWhere('l.public = true AND l.trashedAt IS NULL')->setParameter('s', $set)
            ->getQuery()->getSingleScalarResult();
    }

    public function usedBy(SpriteSet $set): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()->select('COUNT(l.id)')->from(Level::class, 'l')
            ->where('l.spriteSet = :s')->setParameter('s', $set)->getQuery()->getSingleScalarResult();
    }
}
