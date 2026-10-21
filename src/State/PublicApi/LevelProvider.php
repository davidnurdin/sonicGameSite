<?php

namespace App\State\PublicApi;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Level;

/** The public levels (App\ApiResource\Level). */
final class LevelProvider extends PublicProvider implements ProviderInterface
{
    private const ORDERS = ['updated' => ['c.updatedAt', 'DESC'], 'created' => ['c.createdAt', 'DESC'], 'name' => ['c.name', 'ASC']];

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if (!$operation instanceof CollectionOperationInterface) {
            $level = $this->view->em->find(Level::class, (int) $uriVariables['id']);

            return $this->view->visible($level) ? $this->view->level($level) : null;
        }
        $query = CreationQuery::of($this->view->em, Level::class, $context);
        if (($tileset = self::filter($context, 'tileset')) !== null) $query->andWhere('IDENTITY(c.tileset) = :tileset')->setParameter('tileset', (int) $tileset);
        if (($name = self::filter($context, 'name')) !== null) {
            $query->andWhere('c.name LIKE :name')->setParameter('name', '%' . addcslashes($name, '%_\\') . '%');
        }
        [$field, $direction] = self::ORDERS[self::filter($context, 'order') ?? 'updated'] ?? self::ORDERS['updated'];
        $query->orderBy($field, $direction)->addOrderBy('c.id', 'DESC');

        return $this->page($query, $operation, $context, $this->view->level(...));
    }
}
