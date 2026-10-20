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
        return $this->createQueryBuilder('l')->addSelect('t')->join('l.tileset', 't')->where('l.user = :user')
            ->setParameter('user', $user)->orderBy('l.updatedAt', 'DESC')->getQuery()->getResult();
    }

    /**
     * A page of the public levels, the latest changed first.
     *
     * @return array{0: list<Level>, 1: int}
     */
    public function publicPage(int $page, int $perPage): array
    {
        $count = (int) $this->createQueryBuilder('l')->select('COUNT(l.id)')->where('l.public = true')
            ->getQuery()->getSingleScalarResult();
        $list = $this->createQueryBuilder('l')->addSelect('u', 't')->join('l.user', 'u')->join('l.tileset', 't')
            ->where('l.public = true')->orderBy('l.updatedAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        return [$list, $count];
    }
}
