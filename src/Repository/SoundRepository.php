<?php

namespace App\Repository;

use App\Entity\Sound;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Sound> */
class SoundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sound::class);
    }

    /** @return list<Sound> */
    public function ofUser(User $user): array
    {
        return $this->createQueryBuilder('s')->where('s.user = :user')->setParameter('user', $user)
            ->orderBy('s.updatedAt', 'DESC')->getQuery()->getResult();
    }

    /** @return list<Sound> the public ones, the latest first */
    public function publicOnes(int $max = 60): array
    {
        return $this->createQueryBuilder('s')->addSelect('u')->join('s.user', 'u')->where('s.public = true')
            ->orderBy('s.updatedAt', 'DESC')->setMaxResults($max)->getQuery()->getResult();
    }
}
