<?php

namespace App\Repository;

use App\Entity\Replay;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Replay> */
class ReplayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Replay::class);
    }

    /**
     * A page of the history, the latest first: every game ($user: his), an online match once (the replay of
     * the first player who sent it).
     *
     * @return array{0: list<Replay>, 1: int} the replays, how many in all
     */
    public function page(?User $user, int $page, int $perPage): array
    {
        $count = (int) (clone $this->history($user))->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
        $list = $this->history($user)->addSelect('u')->join('r.user', 'u')
            ->orderBy('r.createdAt', 'DESC')->addOrderBy('r.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)
            ->getQuery()->getResult();

        return [$list, $count];
    }

    private function history(?User $user): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r');
        if ($user !== null) return $qb->where('r.user = :user')->setParameter('user', $user);
        $first = $this->getEntityManager()->createQueryBuilder()->select('MIN(r2.id)')->from(Replay::class, 'r2')
            ->where('r2.matchId IS NOT NULL')->groupBy('r2.matchId');

        return $qb->where('r.matchId IS NULL')->orWhere($qb->expr()->in('r.id', $first->getDQL()));
    }

    /** Only the $keep latest replays of $user. */
    public function keepLatest(User $user, int $keep): void
    {
        // the replays of his best times on the levels of the site stay (the ghosts of the records)
        $old = $this->createQueryBuilder('r')->select('r.id')->where('r.user = :user')->setParameter('user', $user)
            ->andWhere('r.id NOT IN (SELECT IDENTITY(lr.replay) FROM ' . \App\Entity\LevelRecord::class . ' lr WHERE lr.user = :user AND lr.replay IS NOT NULL)')
            ->orderBy('r.id', 'DESC')->setFirstResult($keep)->setMaxResults(1000)->getQuery()->getSingleColumnResult();
        if ($old) {
            $this->createQueryBuilder('r')->delete()->where('r.id IN (:ids)')->setParameter('ids', $old)->getQuery()->execute();
        }
    }
}
