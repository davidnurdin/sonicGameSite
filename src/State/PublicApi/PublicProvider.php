<?php

namespace App\State\PublicApi;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

/** What the providers of the public API share: the filters of the request, a page of a query turned into DTOs. */
abstract class PublicProvider
{
    public function __construct(protected PublicView $view, private Pagination $pagination)
    {
    }

    /** A filter of the query string (validated by the parameters of the operation), or null. */
    protected static function filter(array $context, string $name): ?string
    {
        return PublicProviderFilters::get($context, $name);
    }

    /** The page the request asks for, of this query, each result turned into a DTO by $map (one by one, or all at once). */
    protected function page(QueryBuilder $query, Operation $operation, array $context, \Closure $map, bool $all = false): TraversablePaginator
    {
        [$page, $offset, $limit] = $this->pagination->getPagination($operation, $context);
        $paginator = new Paginator($query->setFirstResult($offset)->setMaxResults($limit), fetchJoinCollection: false);
        $rows = iterator_to_array($paginator, false);

        return new TraversablePaginator(new \ArrayIterator($all ? $map($rows) : array_map($map, $rows)), $page, $limit, count($paginator));
    }

    /** The page the request asks for, of a list already made. */
    protected function pageOf(array $items, int $total, Operation $operation, array $context): TraversablePaginator
    {
        [$page, , $limit] = $this->pagination->getPagination($operation, $context);

        return new TraversablePaginator(new \ArrayIterator($items), $page, $limit, $total);
    }
}
