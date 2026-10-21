<?php

namespace App\Repository;

use App\Entity\Member;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Member> */
class MemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Member::class);
    }

    /** @return list<Member> */
    public function of(string $kind, int $id): array
    {
        return $this->findBy(['kind' => $kind, 'creationId' => $id], ['id' => 'ASC']);
    }

    public function isMember(string $kind, int $id, User $user): bool
    {
        return $this->findOneBy(['kind' => $kind, 'creationId' => $id, 'user' => $user]) !== null;
    }

    /** @return list<Member> the creations $user changes with their owners */
    public function ofUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['id' => 'DESC']);
    }
}
