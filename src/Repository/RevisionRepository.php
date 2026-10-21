<?php

namespace App\Repository;

use App\Entity\Revision;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Revision> */
class RevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Revision::class);
    }

    /** @return list<Revision> the history of a creation, the latest first */
    public function of(string $kind, int $id, int $page = 1, int $perPage = 50): array
    {
        return $this->createQueryBuilder('r')->where('r.kind = :kind AND r.creationId = :id')
            ->setParameter('kind', $kind)->setParameter('id', $id)->orderBy('r.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();
    }

    public function countOf(string $kind, int $id): int
    {
        return (int) $this->createQueryBuilder('r')->select('COUNT(r.id)')->where('r.kind = :kind AND r.creationId = :id')
            ->setParameter('kind', $kind)->setParameter('id', $id)->getQuery()->getSingleScalarResult();
    }

    /** The last version of its content (what its editor started from, if nobody saved since). */
    public function lastContent(string $kind, int $id): ?Revision
    {
        return $this->createQueryBuilder('r')->where('r.kind = :kind AND r.creationId = :id AND r.action IN (:actions)')
            ->setParameter('kind', $kind)->setParameter('id', $id)->setParameter('actions', Revision::CONTENT)
            ->orderBy('r.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** @return list<Revision> what $user did lately (the profile shows those of the creations it may show) */
    public function byUser(User $user, int $limit = 40): array
    {
        return $this->createQueryBuilder('r')->where('r.user = :user')->setParameter('user', $user)
            ->orderBy('r.id', 'DESC')->setMaxResults($limit)->getQuery()->getResult();
    }
}
