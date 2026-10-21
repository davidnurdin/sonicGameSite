<?php

namespace App\State\PublicApi;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/** The public creations of one kind, with the filters every list of creations has. */
final class CreationQuery
{
    /** @param string $public the condition that makes one public ('c' is the creation) */
    public static function of(EntityManagerInterface $em, string $class, array $context, string $public = 'c.public = true'): QueryBuilder
    {
        $query = $em->createQueryBuilder()->select('c')->from($class, 'c')->leftJoin('c.user', 'u')->addSelect('u')
            ->where("($public) AND c.trashedAt IS NULL")->orderBy('c.updatedAt', 'DESC')->addOrderBy('c.id', 'DESC');
        if (($author = PublicProviderFilters::get($context, 'author')) !== null) {
            $query->andWhere('u.username = :author')->setParameter('author', strtoupper($author));
        }

        return $query;
    }
}
