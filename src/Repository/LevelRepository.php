<?php

namespace App\Repository;

use App\Entity\Level;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Level> */
class LevelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Level::class);
    }

    /** @return list<Level> */
    public function ofUser(User $user): array
    {
        return $this->createQueryBuilder('l')->addSelect('t')->join('l.tileset', 't')->where('l.user = :user AND l.trashedAt IS NULL')
            ->setParameter('user', $user)->orderBy('l.updatedAt', 'DESC')->getQuery()->getResult();
    }

    /**
     * A page of the public levels, the latest changed first.
     *
     * @return array{0: list<Level>, 1: int}
     */
    public function publicPage(int $page, int $perPage): array
    {
        $count = (int) $this->createQueryBuilder('l')->select('COUNT(l.id)')->where('l.public = true AND l.trashedAt IS NULL')
            ->getQuery()->getSingleScalarResult();
        $list = $this->createQueryBuilder('l')->addSelect('u', 't')->join('l.user', 'u')->join('l.tileset', 't')
            ->where('l.public = true AND l.trashedAt IS NULL')->orderBy('l.updatedAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        return [$list, $count];
    }

    /**
     * What the game's menu of the site's levels shows: those of $user (private ones too) first, then the public
     * levels of the others, the latest changed first.
     *
     * @return array{0: list<Level>, 1: int}
     */
    public function gamePage(?User $user, int $page, int $perPage): array
    {
        $where = ($user !== null ? '(l.public = true OR l.user = :me)' : 'l.public = true') . ' AND l.trashedAt IS NULL';
        $count = $this->createQueryBuilder('l')->select('COUNT(l.id)')->where($where);
        $list = $this->createQueryBuilder('l')->addSelect('u')->join('l.user', 'u')->where($where);
        if ($user !== null) {
            $count->setParameter('me', $user);
            $list->setParameter('me', $user)->addSelect('CASE WHEN l.user = :me THEN 0 ELSE 1 END AS HIDDEN mine')->orderBy('mine');
        }
        $list = $list->addOrderBy('l.updatedAt', 'DESC')->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)
            ->getQuery()->getResult();

        return [$list, (int) $count->getQuery()->getSingleScalarResult()];
    }
}
