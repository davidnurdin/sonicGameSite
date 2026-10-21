<?php

namespace App\Repository;

use App\Entity\Level;
use App\Entity\LevelFavourite;
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

    /** The orders of the catalogue (the site's page of levels, the game's menu, the APIs). */
    public const SORTS = ['top', 'new', 'plays', 'fav'];

    /**
     * A page of the catalogue: the public levels (and, with $user, his own private ones when $mine), in the order
     * $sort (top: the best rated, new: the latest made, plays: the most played, fav: the most often in favourites),
     * those whose name or author contains $search; $favouritesOf: only his favourites (the latest added first).
     *
     * @return array{0: list<Level>, 1: int}
     */
    public function catalogue(string $sort, int $page, int $perPage, ?string $search = null, ?User $favouritesOf = null, ?User $mine = null): array
    {
        $qb = $this->createQueryBuilder('l')->join('l.user', 'u')->where('l.trashedAt IS NULL');
        if ($mine !== null) {
            $qb->andWhere('l.user = :mine')->setParameter('mine', $mine);
        } elseif ($favouritesOf !== null) {
            // a favourite that became private stays in the list of its owner only
            $qb->join(LevelFavourite::class, 'f', 'WITH', 'f.level = l AND f.user = :fan')->setParameter('fan', $favouritesOf)
                ->andWhere('l.public = true OR l.user = :fan');
        } else {
            $qb->andWhere('l.public = true');
        }
        $search = trim((string) $search);
        if ($search !== '') {
            $qb->andWhere('l.name LIKE :q OR u.username LIKE :q')->setParameter('q', '%' . addcslashes(mb_substr($search, 0, 40), '%_\\') . '%');
        }
        $count = (int) (clone $qb)->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();
        $qb->addSelect('u', 't')->join('l.tileset', 't');
        if ($favouritesOf !== null && $mine === null) $qb->orderBy('f.createdAt', 'DESC');
        match ($mine !== null ? 'mine' : $sort) {
            'mine' => $qb->orderBy('l.updatedAt', 'DESC'),
            'new' => $qb->addOrderBy('l.createdAt', 'DESC'),
            'plays' => $qb->addOrderBy('l.plays', 'DESC')->addOrderBy('l.score', 'DESC'),
            'fav' => $qb->addOrderBy('l.favourites', 'DESC')->addOrderBy('l.score', 'DESC'),
            default => $qb->addOrderBy('l.score', 'DESC')->addOrderBy('l.voteCount', 'DESC')->addOrderBy('l.plays', 'DESC'),
        };
        $list = $qb->addOrderBy('l.id', 'DESC')->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

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
